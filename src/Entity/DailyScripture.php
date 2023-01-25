<?php

namespace App\Entity;

use ApiPlatform\Core\Annotation\ApiProperty;
use ApiPlatform\Core\Annotation\ApiResource;
use DateTime;

/** A Daily Scripture Entity */
#[ApiResource(
    collectionOperations: [],
    itemOperations: ['GET'],
)]
class DailyScripture
{
    #[ApiProperty(identifier: true)]
    private DateTime $date;

    private string $body;

    private string $scripture;

    public function __construct(DateTime $date, string $scripture, string $body)
    {
        $this->date = $date;
        $this->scripture = $scripture;
        $this->body = $body;
    }

    /**
     * @return string
     */
    public function getDate(): string
    {
        return $this->date->format('m-d-Y');
    }

    /**
     * @return string
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * @return string
     */
    public function getScripture(): string
    {
        return $this->scripture;
    }
}
