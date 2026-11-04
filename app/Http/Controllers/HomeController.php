<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Presentation\SitePages;
use App\Services\Customers\CheckoutOffer;
use Loom\Action;
use Loom\Request;
use Loom\Response;

#[\Loom\Attributes\Controller]
#[\Loom\Attributes\Route('GET', '/', '', [])]
final class HomeController implements Action
{
    private CheckoutOffer $offer;

    public function __construct(CheckoutOffer $offer)
    {
        $this->offer = $offer;
    }

    public function __invoke(Request $request): Response
    {
        return SitePages::page('home', '从一次体验，开启更多可能', [
            'planName' => $this->offer->name(),
            'planPrice' => $this->offer->price(),
        ]);
    }
}
