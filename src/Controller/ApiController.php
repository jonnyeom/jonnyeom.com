<?php

namespace App\Controller;

use App\Service\DailyScriptureLoader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class ApiController extends AbstractController
{
    private DailyScriptureLoader $dsLoader;

    public function __construct(DailyScriptureLoader $dsLoader)
    {
        $this->dsLoader = $dsLoader;
    }
    /**
     * @Route("api/daily_scriptures/today", "api_daily_scripture")
     */
    public function dailyScripture(): JsonResponse
    {
        $content = $this->dsLoader->getAllScriptures();
        $date = (new \DateTime())->format('n/j/Y');

        return new JsonResponse($content['2022'][$date]);
    }

}
