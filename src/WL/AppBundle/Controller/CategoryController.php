<?php

namespace WL\AppBundle\Controller;

use Nokaut\ApiKit\ClientApi\Rest\Exception\NotFoundException;
use Nokaut\ApiKit\Collection\Products;
use Nokaut\ApiKit\Entity\Category;
use Nokaut\ApiKit\Ext\Data;
use Nokaut\ApiKit\Repository\CategoriesRepository;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;
use WL\AppBundle\Lib\Breadcrumbs\BreadcrumbsBuilder;
use WL\AppBundle\Lib\CategoriesAllowed;
use WL\AppBundle\Lib\Exception\CategoryNotAllowedException;
use WL\AppBundle\Lib\Filter;
use WL\AppBundle\Lib\Helper\Uri;
use WL\AppBundle\Lib\Pagination\Pagination;
use WL\AppBundle\Lib\Repository\ProductsAsyncRepository;
use WL\AppBundle\Lib\Repository\ProductsRepository;
use WL\AppBundle\Lib\Type\Breadcrumb;
use WL\AppBundle\Lib\View\Data\Converter\Filters\Callback;

class CategoryController extends Controller
{
    public function indexAction($categoryUrlWithFilters)
    {
        try {
            $category = $this->fetchCategory($categoryUrlWithFilters);
        } catch (CategoryNotAllowedException $e) {
            return $this->redirect($this->generateUrl('wl_homepage'), 301);
        }

        /** @var ProductsAsyncRepository $productsAsyncRepo */
        $productsAsyncRepo = $this->get('repo.products.async');
        $productsFetch = $productsAsyncRepo->fetchProductsByUrl($categoryUrlWithFilters, $this->getProductFields(), 24);
        $productsAsyncRepo->fetchAllAsync();

        /** @var Products $products */
        $products = $productsFetch->getResult();

        $this->filter($products);
        $this->filterCategory($category);

        $pagination = $this->preparePagination($products);

        $priceFilters = $this->getPriceFilters($products);
        $producersFilters = $this->getProducersFilters($products);
        $propertiesFilters = $this->getPropertiesFilters($products);
        $categoriesFilters = $this->getCategoriesFilters($category, $products);

        $selectedFilters = $this->getSelectedFilters($products);

        $breadcrumbs = $this->prepareBreadcrumbs($category, $selectedFilters);

        $responseStatus = null;
        if ($products->getMetadata()->getTotal() == 0) {
            return $this->render('WLAppBundle:Category:nonResult.html.twig', array(
                'breadcrumbs' => $breadcrumbs,
                'selectedFilters' => $selectedFilters,
                'canonical' => $this->getCanonical($products),
            ), new Response('', 410));
        }

        return $this->render('WLAppBundle:Category:index.html.twig', array(
            'category' => $category,
            'products' => $products,
            'breadcrumbs' => $breadcrumbs,
            'pagination' => $pagination,
            'subcategories' => $categoriesFilters,
            'priceFilters' => $priceFilters,
            'producersFilters' => $producersFilters,
            'propertiesFilters' => $propertiesFilters,
            'selectedFilters' => $selectedFilters,
            'sorts' => $products ? $products->getMetadata()->getSorts() : array(),
            'canonical' => $this->getCanonical($products),
            'h1' => $category->getTitle(),
            'metadataTitle' => $this->prepareMetadataTitle($breadcrumbs, $selectedFilters, $pagination)
        ));
    }

    /**
     * filtering products and facets etc...
     * @param Products $products
     */
    protected function filter($products)
    {
        if ($products === null) {
            return;
        }

        $filterUrl = new Filter\Controller\UrlCategoryFilter();
        $filterUrl->filter($products);

        $filterProperties = new Filter\PropertiesFilter();
        $filterProperties->filterProducts($products);

        $filterSort = new Filter\SortFilter();
        $filterSort->filter($products);
    }

    /**
     * @param Category $category
     */
    protected function filterCategory(Category $category)
    {
        $filterCategory = new Filter\UrlCategoryFilter();
        $filterCategory->filter($category);
    }

    /**
     * @param Products $products
     * @return Pagination
     */
    protected function preparePagination($products)
    {
        if (is_null($products)) {
            return new Pagination();
        }
        $pagination = new Pagination();
        $pagination->setTotal($products->getMetadata()->getPaging()->getTotal());
        $pagination->setCurrentPage($products->getMetadata()->getPaging()->getCurrent());
        $pagination->setUrlTemplate($products->getMetadata()->getPaging()->getUrlTemplate());
        $pagination->setUrlTemplate(
            $this->get('router')->generate('category', array('categoryUrlWithFilters' => $products->getMetadata()->getPaging()->getUrlTemplate()))
        );
        return $pagination;
    }

    /**
     * @param $categoryUrlWithFilters
     * @return Category
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     * @throws CategoryNotAllowedException
     */
    protected function fetchCategory($categoryUrlWithFilters)
    {
        $path = explode('/', $categoryUrlWithFilters);
        $categoryUrl = $path[0];
        /** @var CategoriesRepository $categoriesRepo */
        $categoriesRepo = $this->get('repo.categories');
        try {
            $category = $categoriesRepo->fetchByUrl($categoryUrl);

            if ($category) {
                /** @var CategoriesAllowed $categoriesAllowed */
                $categoriesAllowed = $this->get('categories.allowed');
                $categoriesAllowed->checkAllowedCategory($category);
            }

            return $category;
        } catch (NotFoundException $e) {
            throw $this->createNotFoundException("not found category " . $categoryUrl);
        }
    }

    /**
     * @param $category
     * @param Data\Collection\Filters\FiltersAbstract[] $filters
     * @return array
     */
    protected function prepareBreadcrumbs($category, array $filters)
    {
        /** @var BreadcrumbsBuilder $breadcrumbsBuilder */
        $breadcrumbsBuilder = $this->get('breadcrumb.builder');
        $breadcrumbs = $breadcrumbsBuilder->prepareBreadcrumbs(
            $category,
            function ($url) {
                return $this->get('router')->generate('category', array('categoryUrlWithFilters' => Uri::prepareApiUrl($url)));
            }
        );

        $breadcrumbsBuilder->appendFilter($breadcrumbs, $filters);
        return $breadcrumbs;
    }

    /**
     * @param Products $products
     * @return Data\Collection\Filters\FiltersAbstract[]
     */
    protected function getSelectedFilters($products)
    {
        if (is_null($products)) {
            return array();
        }

        $selectedFilters = array();

        $priceSelectedFilters = $this->getPriceSelectedFilters($products);
        if ($priceSelectedFilters->count()) {
            $selectedFilters[] = $priceSelectedFilters;
        }

        $producersSelectedFilters = $this->getProducersSelectedFilters($products);
        if ($producersSelectedFilters->count()) {
            $selectedFilters[] = $producersSelectedFilters;
        }

        $propertiesSelectedFilters = $this->getPropertiesSelectedFilter($products);
        $selectedFilters = array_merge($selectedFilters, $propertiesSelectedFilters);

        return $selectedFilters;
    }

    /**
     * @return array
     */
    protected function getProductFields()
    {
        $fieldsForList = ProductsRepository::$fieldsForList;
        $fieldsForList[] = '_categories.url_in';
        return $fieldsForList;
    }

    /**
     * @param Products $products
     * @return Data\Collection\Filters\PriceRanges
     */
    protected function getPriceSelectedFilters($products)
    {
        $converterSelectedFilter = new Data\Converter\Filters\Selected\PriceRangesConverter();
        $priceRangesSelectedFilter = $converterSelectedFilter->convert($products, array(
            new Data\Converter\Filters\Callback\PriceRanges\SetIsNofollow(),
            new Callback\PriceRanges\SetName()
        ));
        return $priceRangesSelectedFilter;
    }

    /**
     * @param Products $products
     * @return Data\Collection\Filters\PriceRanges
     */
    protected function getPriceFilters($products)
    {
        $converterFilter = new Data\Converter\Filters\PriceRangesConverter();
        $priceRangesSelectedFilter = $converterFilter->convert($products, array(
            new Data\Converter\Filters\Callback\PriceRanges\SetIsNofollow(),
        ));
        return $priceRangesSelectedFilter;
    }

    /**
     * @param $products
     * @return Data\Collection\Filters\Producers
     */
    protected function getProducersSelectedFilters($products)
    {
        $converterSelectedFilter = new Data\Converter\Filters\Selected\ProducersConverter();
        $producersSelectedFilter = $converterSelectedFilter->convert($products, array(
            new Data\Converter\Filters\Callback\Producers\SetIsNofollow(),
        ));
        return $producersSelectedFilter;
    }

    /**
     * @param $products
     * @return Data\Collection\Filters\Producers
     */
    protected function getProducersFilters($products)
    {
        $converterFilter = new Data\Converter\Filters\ProducersConverter();
        $producersSelectedFilter = $converterFilter->convert($products, array(
            new Data\Converter\Filters\Callback\Producers\SetIsNofollow(),
            new Data\Converter\Filters\Callback\Producers\SetIsPopular(),
            new Data\Converter\Filters\Callback\Producers\SetIsActive(),
            new Data\Converter\Filters\Callback\Producers\SortByName(),
        ));
        return $producersSelectedFilter;
    }

    /**
     * @param Products $products
     * @return Data\Collection\Filters\PropertyAbstract[]
     */
    protected function getPropertiesSelectedFilter($products)
    {
        $converterSelectedFilter = new Data\Converter\Filters\Selected\PropertiesConverter();
        $propertiesFilter = $converterSelectedFilter->convert($products, array(
            new Data\Converter\Filters\Callback\Property\SetIsNofollow(),
        ));
        return $propertiesFilter;
    }

    /**
     * @param Products $products
     * @return Data\Collection\Filters\PropertyAbstract[]
     */
    protected function getPropertiesFilters($products)
    {
        $converterFilter = new Data\Converter\Filters\PropertiesConverter();
        $propertiesFilter = $converterFilter->convert($products, array(
            new Data\Converter\Filters\Callback\Property\SetIsActive(),
            new Data\Converter\Filters\Callback\Property\SetIsExcluded(),
            new Data\Converter\Filters\Callback\Property\SetIsNofollow(),
            new Data\Converter\Filters\Callback\Property\SortDefault(),
        ));
        return $propertiesFilter;
    }

    /**
     * @param Category $category
     * @param Products $products
     * @return Data\Collection\Filters\Categories
     */
    protected function getCategoriesFilters($category, $products)
    {
        $converterFilter = new Data\Converter\Filters\CategoriesConverter();
        $categoriesFilter = $converterFilter->convert($products, array(
            new Callback\Categories\ReduceIncorrectCategories(),
            new Data\Converter\Filters\Callback\Categories\SetIsExcluded(),
            new Data\Converter\Filters\Callback\Categories\SortByName(),
            new Callback\Categories\SetParentCategory($category, $this->get('categories.allowed'))
        ));
        return $categoriesFilter;
    }

    /**
     * @param Breadcrumb[] $breadcrumbs
     * @param array $selectedFilters
     * @param Pagination $pagination
     * @return string
     */
    protected function prepareMetadataTitle($breadcrumbs, $selectedFilters, $pagination)
    {
        $title = "";
        if (count($breadcrumbs)) {
            if ($selectedFilters && count($breadcrumbs) > 1) {
                $title .= $breadcrumbs[count($breadcrumbs) - 2]->getTitle();
            }
            $title .= " " . $breadcrumbs[count($breadcrumbs) - 1]->getTitle();
        }

        if ($pagination->getCurrentPage() > 1) {
            $title .= " (str. " . $pagination->getCurrentPage() . ")";
        }
        return $title;
    }

    /**
     * @param Products $products
     * @return string
     */
    protected function getCanonical($products)
    {
        return $products ? $products->getMetadata()->getCanonical() : '';
    }
}
