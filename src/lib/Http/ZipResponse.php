<?php
declare(strict_types=1);
namespace OCA\HcGridSight\Http;
final class ZipResponse extends \OCP\AppFramework\Http\Response {
    /**
     * Inicializuje instanci a převezme požadované závislosti.
     *
     * Vstupy: `$content` (string) – binární obsah ZIP archivu.
     * Výstup: `void` – nastaví závislosti nebo počáteční stav instance; konstruktor žádnou hodnotu nevrací.
     *
     * @param string $content binární obsah ZIP archivu
     * @return void nastaví závislosti nebo počáteční stav instance; konstruktor žádnou hodnotu nevrací
     */
    public function __construct(private string $content) {
        parent::__construct();
        $this->addHeader('Content-Type','application/zip');
        $this->addHeader('Content-Disposition','attachment; filename="gridsight-reports.zip"');
        $this->addHeader('Cache-Control','no-store');
    }
    /**
     * Sestaví a odešle obsah ZIP odpovědi.
     *
     * Vstupy: žádné vstupní parametry.
     * Výstup: `string` – binární obsah ZIP archivu předaný jako HTTP odpověď.
     * @return string binární obsah ZIP archivu předaný jako HTTP odpověď
     */
    public function render(): string { return $this->content; }
}
