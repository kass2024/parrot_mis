<?php
declare(strict_types=1);

const AGENT_CONTRACT_SENEGAL = 'senegal_fr';
const AGENT_CONTRACT_SENEGAL_TITLE = "Contrat simple d'agent individuel de référencement — Sénégal";
const AGENT_CONTRACT_COMPANY_SIGNER = 'Dr. Jean Pierre Twajamahoro';
const AGENT_CONTRACT_COMPANY_SIGNER_TITLE_FR = 'Propriétaire et directeur général';

function agent_contract_senegal_reference(int $contractId): string
{
    return 'PCVCCL/SN-AGENT/' . $contractId;
}

function agent_contract_senegal_details(?string $json): array
{
    $details = json_decode($json ?? '', true);
    return is_array($details) ? $details : [];
}

function agent_contract_senegal_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function agent_contract_senegal_fields(): array
{
    return [
        'national_id' => ['CNI ou passeport', 64],
        'date_of_birth' => ['Date de naissance', 10],
        'nationality' => ['Nationalité', 100],
        'region' => ['Ville ou région', 100],
        'payment_account' => ["Compte de paiement au nom de l'Agent", 190],
        'signing_place' => ['Lieu de signature', 100],
    ];
}
