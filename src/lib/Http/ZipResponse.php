<?php
declare(strict_types=1);
namespace OCA\HcGridSight\Http;
final class ZipResponse extends \OCP\AppFramework\Http\Response {
    public function __construct(private string $content) {
        parent::__construct();
        $this->addHeader('Content-Type','application/zip');
        $this->addHeader('Content-Disposition','attachment; filename="gridsight-reports.zip"');
        $this->addHeader('Cache-Control','no-store');
    }
    public function render(): string { return $this->content; }
}
