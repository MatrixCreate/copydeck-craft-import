<?php

namespace matrixcreate\copydeckimporter\models;

use craft\base\Model;

/**
 * Plugin settings model.
 *
 * Stores Copydeck API connection details used by the sync flow.
 * Saved via Craft's built-in plugin settings mechanism (project config).
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.2.0
 */
class Settings extends Model
{
    /**
     * Base URL of the Copydeck instance (e.g. https://copydeck.agency.com).
     *
     * @var string
     */
    public string $copydeckUrl = '';

    /**
     * API key from Copydeck project settings.
     *
     * @var string
     */
    public string $apiKey = '';

    /**
     * @inheritdoc
     */
    public function defineRules(): array
    {
        return [
            [['copydeckUrl', 'apiKey'], 'string'],
            ['copydeckUrl', 'url', 'defaultScheme' => 'https', 'when' => fn() => $this->copydeckUrl !== ''],
        ];
    }
}
