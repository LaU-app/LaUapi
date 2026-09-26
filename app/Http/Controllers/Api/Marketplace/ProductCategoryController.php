<?php

namespace App\Http\Controllers\Api\Marketplace;

use App\Models\ProductCategory;

class ProductCategoryController extends MarketplaceController
{
    public function index()
    {
        return $this->respond(ProductCategory::active()->get());
    }
}
