<?php

namespace App\Services;

use App\Models\SsoClient;
use Illuminate\Database\Eloquent\Collection;

class SsoClientService
{
    public function all(): Collection
    {
        return SsoClient::withCount('sessions')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function create(array $data): SsoClient
    {
        if (!isset($data['grant_types'])) {
            $data['grant_types'] = ['authorization_code'];
        }

        return SsoClient::create($data);
    }

    public function update(SsoClient $client, array $data): SsoClient
    {
        $client->update($data);
        return $client;
    }

    public function delete(SsoClient $client): void
    {
        $client->sessions()->update(['sso_client_id' => null]);
        $client->delete();
    }
}
