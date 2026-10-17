<?php

namespace App\Http\Controllers;

use App\Http\Requests\Products\ProductIndexRequest;
use App\Services\Contracts\ProductServiceInterface;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Product catalogue (read only): the page of the role (its own view in the role's folder under pages/platform, e.g.
 * platform/shop/leader/products/index) with the categories on the left. What the person may see is decided by the
 * service.
 */
class ProductController extends Controller
{
    public function __construct(private readonly ProductServiceInterface $products)
    {
        //
    }

    public function index(ProductIndexRequest $request): Response
    {
        $user = $request->user();

        return Inertia::render($user->role->pageGroup().'/'.$user->role->slug().'/products/index', $this->products->catalog($user, $request->validated()));
    }
}
