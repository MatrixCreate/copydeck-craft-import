<?php

namespace matrixcreate\copydeckimporter;

use Craft;
use craft\base\Plugin;
use craft\elements\Entry;
use craft\events\DefineHtmlEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use craft\base\Element;
use craft\base\Model;
use craft\db\Query;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use matrixcreate\copydeckimporter\models\Settings;
use matrixcreate\copydeckimporter\services\CopydeckApiService;
use matrixcreate\copydeckimporter\services\ImageImportService;
use matrixcreate\copydeckimporter\services\ImportService;
use matrixcreate\copydeckimporter\services\MatrixBuilder;
use matrixcreate\copydeckimporter\services\NodesRenderer;
use yii\base\Event;

/**
 * Copydeck Importer plugin.
 *
 * @property-read CopydeckApiService $api
 * @property-read ImageImportService $images
 * @property-read ImportService $imports
 * @property-read MatrixBuilder $matrixBuilder
 * @property-read NodesRenderer $nodes
 * @property-read Settings $settings
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.0.0
 */
class CopydeckImporter extends Plugin
{
    // Constants
    // =========================================================================

    /** @var string */
    public const VERSION = '1.1.0';

    // Static Properties
    // =========================================================================

    /** @var CopydeckImporter|null */
    public static ?CopydeckImporter $plugin = null;

    // Public Properties
    // =========================================================================

    /**
     * @var bool
     */
    public bool $hasCpSection = true;

    /**
     * @var bool
     */
    public bool $hasCpSettings = true;

    /**
     * @var string
     */
    public string $schemaVersion = '1.0.0';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        self::$plugin = $this;

        $this->setComponents([
            'api' => CopydeckApiService::class,
            'images' => ImageImportService::class,
            'imports' => ImportService::class,
            'matrixBuilder' => MatrixBuilder::class,
            'nodes' => NodesRenderer::class,
        ]);

        $this->_registerCpRoutes();
        $this->_registerEntrySidebar();

        Craft::info(
            Craft::t('copydeck-importer', '{name} plugin loaded', ['name' => $this->name]),
            __METHOD__,
        );
    }

    /**
     * @inheritdoc
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();

        $item['label'] = 'Copydeck';
        $item['icon']  = 'copyright';

        return $item;
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * @inheritdoc
     */
    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate(
            'copydeck-importer/_cp/settings',
            ['settings' => $this->getSettings()],
        );
    }

    // Private Methods
    // =========================================================================

    /**
     * Registers CP URL rules.
     *
     * @return void
     */
    private function _registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['copydeck-importer']                         = 'copydeck-importer/cp/index';
                $event->rules['copydeck-importer/history']                 = 'copydeck-importer/cp/history';
                $event->rules['copydeck-importer/upload']                  = 'copydeck-importer/cp/upload';
                $event->rules['copydeck-importer/preview']                 = 'copydeck-importer/cp/preview';
                $event->rules['copydeck-importer/import']                  = 'copydeck-importer/cp/run-import';
                $event->rules['copydeck-importer/result/<runId:\d+>']      = 'copydeck-importer/cp/result';
                $event->rules['copydeck-importer/sync']                    = 'copydeck-importer/cp/sync';
                $event->rules['copydeck-importer/sync/run']                = 'copydeck-importer/cp/run-sync';
                $event->rules['copydeck-importer/sync/status']             = 'copydeck-importer/cp/sync-status';
                $event->rules['copydeck-importer/sync/result/<runId:\d+>'] = 'copydeck-importer/cp/sync-result';
                $event->rules['copydeck-importer/widget-sync']             = 'copydeck-importer/cp/widget-sync';
            },
        );
    }

    /**
     * Appends a Copydeck sync widget to the entry edit screen sidebar.
     *
     * Uses Entry::EVENT_DEFINE_SIDEBAR_HTML (fires on every entry edit screen
     * render). The handler appends to $event->html — never replaces it.
     *
     * Only renders when the entry has been saved (has an id and a slug).
     *
     * @return void
     */
    private function _registerEntrySidebar(): void
    {
        Event::on(
            Entry::class,
            Element::EVENT_DEFINE_SIDEBAR_HTML,
            static function (DefineHtmlEvent $event) {
                /** @var Entry $entry */
                $entry = $event->sender;

                if (!$entry->id || !$entry->slug) {
                    return;
                }

                $elementId = $entry->id;
                $slug      = $entry->slug;

                // Last successful sync timestamp for this entry.
                $syncedAt = (new Query())
                    ->select(['synced_at'])
                    ->from('{{%copydeck_entry_syncs}}')
                    ->where(['element_id' => $elementId])
                    ->scalar();

                if ($syncedAt) {
                    $syncedAtFormatted = Craft::$app->getFormatter()->asDatetime($syncedAt, 'short');
                } else {
                    $syncedAtFormatted = Craft::t('copydeck-importer', 'Never');
                }

                $widgetId  = 'copydeck-sync-' . $elementId;
                $actionUrl = UrlHelper::actionUrl('copydeck-importer/cp/widget-sync');
                $csrfToken = Craft::$app->getRequest()->getCsrfToken();

                // fieldset + legend matches Craft's native sidebar section style.
                $html  = '<fieldset>';
                $html .= '<legend class="h6">' . Html::encode('COPYDECK') . '</legend>';
                $html .= Html::beginTag('div', ['id' => $widgetId, 'class' => 'meta']);

                // Status row — label + Sync button.
                $html .= '<div class="field">';
                $html .= '<div class="heading"><label>' . Html::encode(Craft::t('copydeck-importer', 'Status')) . '</label></div>';
                $html .= '<div class="input ltr">';
                $html .= Html::button(
                    Html::encode(Craft::t('copydeck-importer', 'Sync')),
                    [
                        'type'  => 'button',
                        'class' => 'btn small',
                        'id'    => $widgetId . '-btn',
                        'data'  => [
                            'element-id' => $elementId,
                            'slug'       => $slug,
                        ],
                    ],
                );
                $html .= '</div></div>';

                // Synced at row.
                $html .= '<div class="field">';
                $html .= '<div class="heading"><label>' . Html::encode(Craft::t('copydeck-importer', 'Synced at')) . '</label></div>';
                $html .= '<div class="input ltr">';
                $html .= Html::tag('span', Html::encode($syncedAtFormatted), [
                    'id'    => $widgetId . '-timestamp',
                    'class' => 'light',
                ]);
                $html .= '</div></div>';

                $html .= Html::endTag('div'); // .meta
                $html .= '</fieldset>';

                // Inline JS — POSTs to the CP controller action, updates the
                // timestamp on success without a page reload.
                $js = <<<JS
(function() {
    var btn = document.getElementById('{$widgetId}-btn');
    var ts  = document.getElementById('{$widgetId}-timestamp');
    if (!btn) return;

    btn.addEventListener('click', function() {
        btn.textContent = 'Syncing\u2026';
        btn.disabled = true;

        fetch('{$actionUrl}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept':        'application/json',
                'X-CSRF-Token':  '{$csrfToken}',
            },
            body: JSON.stringify({
                elementId: {$elementId},
                slug:      '{$slug}',
            }),
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                btn.textContent = 'Synced \u2713';
                if (ts && data.syncedAt) {
                    ts.textContent = data.syncedAt;
                }
                setTimeout(function() {
                    btn.textContent = 'Sync';
                    btn.disabled = false;
                }, 3000);
            } else {
                btn.textContent = 'Error';
                Craft.cp.displayError(data.error || 'Sync failed');
                setTimeout(function() {
                    btn.textContent = 'Sync';
                    btn.disabled = false;
                }, 3000);
            }
        })
        .catch(function() {
            btn.textContent = 'Error';
            setTimeout(function() {
                btn.textContent = 'Sync';
                btn.disabled = false;
            }, 3000);
        });
    });
})();
JS;

                $html .= Html::tag('script', $js);

                $event->html .= $html;
            },
        );
    }
}
