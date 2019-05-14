<?php

namespace App\Controller;

use Symfony\Component\Routing\Annotation\Route;

class BlogController extends BaseController
{
    /**
     * @Route("/blog", name="app_blog")
     */
    public function blog()
    {
        return $this->inProgress('Blog');
    }

}
