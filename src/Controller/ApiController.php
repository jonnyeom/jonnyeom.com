<?php

namespace App\Controller;

use App\Service\DailyScriptureLoader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class ApiController extends AbstractController
{
    /**
     * @Route("api/daily_scriptures/today", "api_daily_scripture")
     */
    public function dailyScriptures(): JsonResponse
    {
        $content = (new DailyScriptureLoader())->getAllScriptures();
        $date = (new \DateTime())->format('n/j/Y');

        return new JsonResponse($content['2022'][$date]);
    }

}
