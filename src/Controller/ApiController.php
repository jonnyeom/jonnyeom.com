<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\DailyScriptureLoader;
use DateTime;
use DateTimeZone;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

use function md5;

class ApiController extends AbstractController
{
    public function __construct(private DailyScriptureLoader $dsLoader)
    {
    }

    #[Route(path: 'api/daily_scriptures/today')]
    public function dailyScripture(Request $request): JsonResponse
    {
        $content = $this->dsLoader->getAllScriptures();
        $date    = (new DateTime('now', new DateTimeZone('America/New_York')))->format('n/j/Y');

        $response = new JsonResponse($content['2022'][$date]);
        $response->setEtag(md5($response->getContent()));
        $response->setPublic();
        $response->isNotModified($request);

        return $response;
    }
}
