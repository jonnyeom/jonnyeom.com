<?php

trait ChildTrait {

    abstract private function abstractPrivateTraitMethod(): bool;

    public function childTraitMethod(string $param): bool {
        return TRUE;
    }
}