<?php

namespace WL\MaterializeTemplateBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class WLMaterializeTemplateBundle extends Bundle
{
    public function getParent()
    {
        return 'WLAppBundle';
    }
}
