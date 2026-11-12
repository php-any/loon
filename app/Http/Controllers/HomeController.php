<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Presentation\SitePages;
use App\Services\Customers\CheckoutOffer;
use Loom\Request;
use Loom\Response;

#[\Net\Annotation\Controller]
final class HomeController
{
    private CheckoutOffer $offer;

    public function __construct(CheckoutOffer $offer)
    {
        $this->offer = $offer;
    }

    #[\Net\Annotation\GetMapping('/')]
    public function page(Request $request): Response
    {
        return SitePages::page('home', '从一次体验，开启更多可能', [
            'planName' => $this->offer->name(),
            'planPrice' => $this->offer->price(),
        ]);
    }
}
