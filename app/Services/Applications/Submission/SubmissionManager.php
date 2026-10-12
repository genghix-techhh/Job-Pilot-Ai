<?php

namespace App\Services\Applications\Submission;

use App\Models\Application;

class SubmissionManager
{
    /** @var list<SubmissionGateway> */
    private array $gateways = [];

    public function register(SubmissionGateway $gateway): void
    {
        $this->gateways[] = $gateway;
    }

    public function gatewayFor(Application $application): ?SubmissionGateway
    {
        foreach ($this->gateways as $gateway) {
            if ($gateway->supports($application)) {
                return $gateway;
            }
        }

        return null;
    }

    public function hasAny(): bool
    {
        return $this->gateways !== [];
    }
}
