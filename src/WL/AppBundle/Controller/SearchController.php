<?php

namespace WL\AppBundle\Controller;

use Nokaut\ApiKit\Collection\Products;
use Nokaut\ApiKit\Entity\Category;
use Nokaut\ApiKit\Entity\Product;
use Symfony\Component\HttpFoundation\Response;
use WL\AppBundle\Lib\BreadcrumbsBuilder;
use WL\AppBundle\Lib\Filter\PropertiesFilter;
use WL\AppBundle\Lib\Filter\UrlFilter;
use WL\AppBundle\Lib\Helper\UrlSearch;
use WL\AppBundle\Lib\Pagination\Pagination;
use WL\AppBundle\Lib\Type\Breadcrumb;
use WL\AppBundle\Lib\Repository\ProductsAsyncRepository;
use WL\AppBundle\Lib\View\Data\Converter\Filters\Callback;
use Nokaut\ApiKit\Ext\Data;

class SearchController extends CategoryController
{
    public function indexAction($phrase)
    {
        /** @var UrlSearch $urlSearchPreparer */
        $urlSearchPreparer = $this->get('helper.url_search');
        $phraseUrlForApi = $urlSearchPreparer->preparePhraseWithAllowCategories($phrase);

        /** @var ProductsAsyncRepository $productsRepo */
        $productsRepo = $this->get('repo.products.async');
        $productsFetch = $productsRepo->fetchProductsByUrl($phraseUrlForApi, $this->getProductFields(), 24);
        $productsRepo->fetchAllAsync();
        /** @var Products $products */
        $products = $productsFetch->getResult();

        $this->filter($products);

        $pagination = $this->preparePagination($products);

        $priceFilters = $this->getPriceFilters($products);
        $producersFilters = $this->getProducersFilters($products);
        $propertiesFilters = $this->getPropertiesFilter($products);
        $categoriesFilters = $this->getCategoriesFilter($products);

        $selectedFilters = $this->getSelectedFilters($products);

        $breadcrumbs = $this->prepareBreadcrumbs($products, $selectedFilters);

        $phrase = $products ? $products->getMetadata()->getQuery()->getPhrase() : '';

        $responseStatus = null;
        if ($products->getMetadata()->getTotal() == 0) {
            return $this->render('WLAppBundle:Category:nonResult.html.twig', array(
                'phrase' => $phrase,
                'breadcrumbs' => $breadcrumbs,
                'selectedFilters' => $selectedFilters,
                'canonical' => $products ? $products->getMetadata()->getCanonical() : '',
            ), new Response('', 404));
        }

        return $this->render('WLAppBundle:Search:index.html.twig', array(
            'products' => $products,
            'phrase' => $phrase,
            'breadcrumbs' => $breadcrumbs,
            'pagination' => $pagination,
            'subcategories' => $categoriesFilters,
            'priceFilters' => $priceFilters,
            'producersFilters' => $producersFilters,
            'propertiesFilters' => $propertiesFilters,
            'selectedFilters' => $selectedFilters,
            'sorts' => $products ? $products->getMetadata()->getSorts() : array(),
            'canonical' => $products ? $products->getMetadata()->getCanonical() : '',
            'h1' => $phrase
        ), $responseStatus);
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
        $filterUrl = new UrlFilter($this->get('helper.url_search'));
        $filterUrl->filter($products);

        $filterProperties = new PropertiesFilter();
        $filterProperties->filterProducts($products);
    }

    /**
     * @param Products|null $products
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
        $pagination->setUrlTemplate(
            $this->get('router')->generate('search', array('phrase' => ltrim($products->getMetadata()->getPaging()->getUrlTemplate(), '/')))
        );
        return $pagination;
    }

    /**
     * @param Products $products
     * @param Data\Collection\Filters\FiltersAbstract[] $filters
     * @return Breadcrumb[]
     */
    protected function prepareBreadcrumbs($products, array $filters)
    {
        $breadcrumbs = array();
        $breadcrumbs[] = new Breadcrumb("Szukaj: " . $products->getMetadata()->getQuery()->getPhrase());
        /** @var BreadcrumbsBuilder $breadcrumbsBuilder */
        $breadcrumbsBuilder = $this->get('breadcrumb.builder');
        $breadcrumbsBuilder->appendFilter($breadcrumbs, $filters);
        return $breadcrumbs;
    }

}
