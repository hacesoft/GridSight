<?php

declare(strict_types=1);

namespace OCA\HcGridSight\Controller;

use OCA\HcGridSight\AppInfo\Application;
use OCA\HcGridSight\Service\EnergyAnalysisService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

final class EnergyAnalysisController extends Controller {
    public function __construct(
        private IRequest $httpRequest,
        private EnergyAnalysisService $analysis,
        private IUserSession $users,
        private IGroupManager $groups,
    ) {
        parent::__construct(Application::APP_ID, $httpRequest);
    }

    #[NoAdminRequired]
    public function overview(string $period = ''): DataResponse {
        try { return new DataResponse(['ok'=>true,'data'=>$this->analysis->overview($period)]); }
        catch (\InvalidArgumentException $error) { return new DataResponse(['error'=>$error->getMessage()],400); }
    }

    #[NoAdminRequired]
    public function saveProfile(array $profile = []): DataResponse {
        if (!$this->isAdmin()) return new DataResponse(['error'=>'Administrator privileges are required.'],403);
        try { return new DataResponse(['ok'=>true,'profile'=>$this->analysis->saveProfile($profile)]); }
        catch (\InvalidArgumentException $error) { return new DataResponse(['error'=>$error->getMessage()],400); }
    }

    #[NoAdminRequired]
    public function importReport(): DataResponse {
        if (!$this->isAdmin()) return new DataResponse(['error'=>'Administrator privileges are required.'],403);
        $file = $this->httpRequest->getUploadedFile('file');
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return new DataResponse(['error'=>'Upload an ED.G XLSX report.'],400);
        }
        try {
            return new DataResponse(['ok'=>true,'result'=>$this->analysis->importReport(
                (string)($file['tmp_name'] ?? ''), (string)($file['name'] ?? ''),
            )]);
        } catch (\InvalidArgumentException $error) {
            return new DataResponse(['error'=>$error->getMessage()],400);
        }
    }


    #[NoAdminRequired]
    public function deleteReport(string $period): DataResponse {
        if (!$this->isAdmin()) return new DataResponse(['error'=>'Administrator privileges are required.'],403);
        try { $this->analysis->deleteReport($period); return new DataResponse(['ok'=>true]); }
        catch (\InvalidArgumentException $e) { return new DataResponse(['error'=>$e->getMessage()],400); }
    }
    #[NoAdminRequired]
    public function exportReports(array $periods=[]): \OCP\AppFramework\Http\Response {
        try { return new \OCA\HcGridSight\Http\ZipResponse($this->analysis->exportReports($periods)); }
        catch (\InvalidArgumentException $e) { return new DataResponse(['error'=>$e->getMessage()],400); }
    }
    #[NoAdminRequired]
    public function saveStatement(array $statement=[]): DataResponse {
        if (!$this->isAdmin()) return new DataResponse(['error'=>'Administrator privileges are required.'],403);
        try { return new DataResponse(['ok'=>true,'statement'=>$this->analysis->saveStatement($statement)]); }
        catch (\InvalidArgumentException $e) { return new DataResponse(['error'=>$e->getMessage()],400); }
    }
    #[NoAdminRequired]
    public function deleteStatement(string $id): DataResponse {
        if (!$this->isAdmin()) return new DataResponse(['error'=>'Administrator privileges are required.'],403);
        try { $this->analysis->deleteStatement($id); return new DataResponse(['ok'=>true]); }
        catch (\InvalidArgumentException $e) { return new DataResponse(['error'=>$e->getMessage()],400); }
    }
    private function isAdmin(): bool {
        $uid = $this->users->getUser()?->getUID();
        return $uid !== null && $this->groups->isAdmin($uid);
    }
}
