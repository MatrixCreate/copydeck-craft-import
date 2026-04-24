<?php

namespace matrixcreate\copydeckimporter\services;

use Craft;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use matrixcreate\copydeckimporter\CopydeckImporter;
use yii\base\Component;

/**
 * Handles communication with the Copydeck API.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.2.0
 */
class CopydeckApiService extends Component
{
    /**
     * Fetches the full project export from the Copydeck API.
     *
     * Calls GET {copydeckUrl}/api/v1/projects/{projectSlug}/export
     * with Bearer token authentication.
     *
     * @return array{success: bool, data: array|null, error: string|null}
     */
    public function fetchExport(): array
    {
        $settings = CopydeckImporter::$plugin->getSettings();

        $url = rtrim($settings->copydeckUrl, '/');
        $key = $settings->apiKey;

        if ($url === '' || $key === '') {
            return [
                'success' => false,
                'data'    => null,
                'error'   => 'Copydeck API is not fully configured. Set URL and API key in plugin settings.',
            ];
        }

        $endpoint = "{$url}/api/v1/export";

        try {
            $response = Craft::createGuzzleClient()->request('GET', $endpoint, [
                RequestOptions::HEADERS => [
                    'Accept'        => 'application/json',
                    'Authorization' => "Bearer {$key}",
                ],
                RequestOptions::TIMEOUT         => 120,
                RequestOptions::CONNECT_TIMEOUT  => 10,
            ]);

            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return [
                    'success' => false,
                    'data'    => null,
                    'error'   => 'Copydeck returned invalid JSON: ' . json_last_error_msg(),
                ];
            }

            return [
                'success' => true,
                'data'    => $data,
                'error'   => null,
            ];
        } catch (GuzzleException $e) {
            Craft::error("Copydeck API request failed: {$e->getMessage()}", __METHOD__);

            return [
                'success' => false,
                'data'    => null,
                'error'   => 'API request failed: ' . $e->getMessage(),
            ];
        }
    }
}
