<?php

/**
 * Hacesoft GridSight – typová legenda proměnných (maďarská notace).
 * Historie: 2026-11-21 – bezpečné sjednocení interních lokálních proměnných.
 * Historie: 2026-11-22 – ověření typů, významových komentářů a shody názvů vlastností.
 * s – textový řetězec / identifikátor nebo cesta.
 * o – instance objektu nebo výjimka.
 * Výjimka: názvy parametrů PHP metod a veřejných vlastností se nemění,
 * protože jsou součástí rozhraní Nextcloud, DI a pojmenovaných argumentů.
 */

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
    /**
     * Inicializuje instanci a převezme požadované závislosti.
     *
     * Vstupy: `$httpRequest` (IRequest) – HTTP požadavek včetně příloh; `$analysis` (EnergyAnalysisService) – služba účtování EG.D, SPOT a smluv; `$users` (IUserSession) – služba aktuálního přihlášeného uživatele; `$groups` (IGroupManager) – správa skupin a členství.
     * Výstup: `void` – nastaví závislosti nebo počáteční stav instance; konstruktor žádnou hodnotu nevrací.
     *
     * @param IRequest $httpRequest HTTP požadavek včetně příloh
     * @param EnergyAnalysisService $analysis služba účtování EG.D, SPOT a smluv
     * @param IUserSession $users služba aktuálního přihlášeného uživatele
     * @param IGroupManager $groups správa skupin a členství
     * @return void nastaví závislosti nebo počáteční stav instance; konstruktor žádnou hodnotu nevrací
     */
    public function __construct(
        private IRequest $httpRequest,
        private EnergyAnalysisService $analysis,
        private IUserSession $users,
        private IGroupManager $groups,
    ) {
        parent::__construct(Application::APP_ID, $httpRequest);
    }

    /**
     * Vrátí přehled účtování za vybrané období včetně energetických a cenových součtů.
     *
     * Vstupy: `$period` (string) – měsíc reportu ve tvaru YYYY-MM; prázdné znamená výchozí výběr.
     * Výstup: `DataResponse` – JSON přehled smluv, výkazů a vyúčtování za zvolený měsíc.
     *
     * @param string $period měsíc reportu ve tvaru YYYY-MM; prázdné znamená výchozí výběr
     * @return DataResponse JSON přehled smluv, výkazů a vyúčtování za zvolený měsíc
     */
    #[NoAdminRequired]
    public function overview(string $period = ''): DataResponse {
        try { return new DataResponse(['ok'=>true,'data'=>$this->analysis->overview($period)]); }
        catch (\InvalidArgumentException $sError) { return new DataResponse(['error'=>$sError->getMessage()],400); }
    }

    /**
     * Ověří a uloží údaje odběrného místa, smluv, cen a tarifů.
     *
     * Vstupy: `$profile` (array) – aktuální profil odběrného místa, ceníků a HDO.
     * Výstup: `DataResponse` – JSON se stavem uložení profilu odběrného místa.
     *
     * @param array $profile aktuální profil odběrného místa, ceníků a HDO
     * @return DataResponse JSON se stavem uložení profilu odběrného místa
     */
    #[NoAdminRequired]
    public function saveProfile(array $profile = []): DataResponse {
        if (!$this->isAdmin()) return new DataResponse(['error'=>'Administrator privileges are required.'],403);
        try { return new DataResponse(['ok'=>true,'profile'=>$this->analysis->saveProfile($profile)]); }
        catch (\InvalidArgumentException $sError) { return new DataResponse(['error'=>$sError->getMessage()],400); }
    }

    /**
     * Přečte a ověří měsíční XLSX report EG.D; uloží nezávislé čtvrthodinové intervaly.
     *
     * Vstupy: žádné vstupní parametry.
     * Výstup: `DataResponse` – JSON s výsledkem importu souboru EG.D nebo chybou validace.
     * @return DataResponse JSON s výsledkem importu souboru EG.D nebo chybou validace
     */
    #[NoAdminRequired]
    public function importReport(): DataResponse {
        if (!$this->isAdmin()) return new DataResponse(['error'=>'Administrator privileges are required.'],403);
        $vFile = $this->httpRequest->getUploadedFile('file');
        if (!is_array($vFile) || ($vFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return new DataResponse(['error'=>'Upload an ED.G XLSX report.'],400);
        }
        try {
            return new DataResponse(['ok'=>true,'result'=>$this->analysis->importReport(
                (string)($vFile['tmp_name'] ?? ''), (string)($vFile['name'] ?? ''),
            )]);
        } catch (\InvalidArgumentException $sError) {
            return new DataResponse(['error'=>$sError->getMessage()],400);
        }
    }


    /**
     * Odstraní vybraný importovaný report EG.D a jeho intervaly bez zásahu do historie LINEA.
     *
     * Vstupy: `$period` (string) – měsíc reportu ve tvaru YYYY-MM; prázdné znamená výchozí výběr.
     * Výstup: `DataResponse` – JSON s potvrzením smazání reportu EG.D.
     *
     * @param string $period měsíc reportu ve tvaru YYYY-MM; prázdné znamená výchozí výběr
     * @return DataResponse JSON s potvrzením smazání reportu EG.D
     */
    #[NoAdminRequired]
    public function deleteReport(string $period): DataResponse {
        if (!$this->isAdmin()) return new DataResponse(['error'=>'Administrator privileges are required.'],403);
        try { $this->analysis->deleteReport($period); return new DataResponse(['ok'=>true]); }
        catch (\InvalidArgumentException $oE) { return new DataResponse(['error'=>$oE->getMessage()],400); }
    }
    /**
     * Připraví export vybraných měsíčních reportů EG.D do ZIP archivu.
     *
     * Vstupy: `$periods` (array) – seznam měsíců YYYY-MM určených k exportu.
     * Výstup: `\OCP\AppFramework\Http\Response` – binární ZIP odpověď s vybranými výkazy EG.D.
     *
     * @param array $periods seznam měsíců YYYY-MM určených k exportu
     * @return \OCP\AppFramework\Http\Response binární ZIP odpověď s vybranými výkazy EG.D
     */
    #[NoAdminRequired]
    public function exportReports(array $periods=[]): \OCP\AppFramework\Http\Response {
        try { return new \OCA\HcGridSight\Http\ZipResponse($this->analysis->exportReports($periods)); }
        catch (\InvalidArgumentException $oE) { return new DataResponse(['error'=>$oE->getMessage()],400); }
    }
    /**
     * Ověří a uloží ruční vyúčtování prodeje Delta Green.
     *
     * Vstupy: `$statement` (array) – položky ručního vyúčtování Delta Green.
     * Výstup: `DataResponse` – JSON s potvrzením uloženého vyúčtování.
     *
     * @param array $statement položky ručního vyúčtování Delta Green
     * @return DataResponse JSON s potvrzením uloženého vyúčtování
     */
    #[NoAdminRequired]
    public function saveStatement(array $statement=[]): DataResponse {
        if (!$this->isAdmin()) return new DataResponse(['error'=>'Administrator privileges are required.'],403);
        try { return new DataResponse(['ok'=>true,'statement'=>$this->analysis->saveStatement($statement)]); }
        catch (\InvalidArgumentException $oE) { return new DataResponse(['error'=>$oE->getMessage()],400); }
    }
    /**
     * Odstraní vybrané vyúčtování prodeje Delta Green.
     *
     * Vstupy: `$id` (string) – význam parametru podle rozhraní metody.
     * Výstup: `DataResponse` – JSON s potvrzením smazání vyúčtování.
     *
     * @param string $id význam parametru podle rozhraní metody
     * @return DataResponse JSON s potvrzením smazání vyúčtování
     */
    #[NoAdminRequired]
    public function deleteStatement(string $id): DataResponse {
        if (!$this->isAdmin()) return new DataResponse(['error'=>'Administrator privileges are required.'],403);
        try { $this->analysis->deleteStatement($id); return new DataResponse(['ok'=>true]); }
        catch (\InvalidArgumentException $oE) { return new DataResponse(['error'=>$oE->getMessage()],400); }
    }
    /**
     * Ověří oprávnění přihlášeného uživatele ke správě společných dat.
     *
     * Vstupy: žádné vstupní parametry.
     * Výstup: `bool` – true pouze u uživatele oprávněného provádět administrátorské změny.
     * @return bool true pouze u uživatele oprávněného provádět administrátorské změny
     */
    private function isAdmin(): bool {
        $sUid = $this->users->getUser()?->getUID();
        return $sUid !== null && $this->groups->isAdmin($sUid);
    }
}
