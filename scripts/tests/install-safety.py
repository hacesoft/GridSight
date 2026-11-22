#!/usr/bin/env python3
"""Regresní test pro shell uvnitř `docker exec … sh -c` při instalaci.

Zachytí skutečný argument předaný Dockeru, provede jeho `sh -n` a pak
na dočasné kopii se simulovaným Nextcloudem ověří bezpečnost přesunu.
Žádný Docker, skutečný custom_apps ani produkční data nejsou použity.
"""
import os
import pathlib
import subprocess
import tempfile

ROOT = pathlib.Path(__file__).resolve().parents[2]
SAFETY = ROOT / 'scripts' / 'custom-apps-safety.sh'


def must(ok, message):
    if not ok:
        raise AssertionError(message)


def execute(command, *, input_text=None, env=None):
    return subprocess.run(command, input=input_text, text=True,
                          capture_output=True, env=env, check=False)


with tempfile.TemporaryDirectory(prefix='hc-gridsight-installer-test-') as tmp:
    work = pathlib.Path(tmp)
    capture = work / 'nested.sh'
    env = dict(os.environ, HC_CAPTURE=str(capture))
    # Shell vyhodnotí citace úplně stejně jako při skutečném docker exec.
    # Mock funkce docker jen zachytí pátý parametr, což je vnitřní `sh -c`.
    shell = '''
      . "$1"
      docker() {
        [ "$1" = exec ] && [ "$3" = sh ] && [ "$4" = -c ] || exit 31
        printf '%s\n' "$5" > "$HC_CAPTURE"
      }
      hc_clean_custom_apps hc_gridsight nextcloud-app
    '''
    result = execute(['sh', '-c', shell, 'sh', str(SAFETY)], env=env)
    must(result.returncode == 0 and capture.is_file(),
         'Nelze zachytit vnitřní shell: ' + result.stderr)
    nested = capture.read_text()
    result = execute(['sh', '-n'], input_text=nested)
    must(result.returncode == 0,
         'Neplatná syntaxe docker exec sh -c: ' + result.stderr)
    # Negativní regresní test: chyba `for in` musí skutečně selhat.
    must('for candidate in "$root"/*; do' in nested,
         'Chybí přesně ověřený průchod adresářů.')
    damaged = nested.replace('for candidate in "$root"/*; do',
                             'for in "$root"/*; do')
    must(execute(['sh', '-n'], input_text=damaged).returncode != 0,
         'Regresní kontrola neodhalila známou chybu `for in`.')

    app_root = work / 'custom_apps'
    data_dir = work / 'nc-data'
    app_root.mkdir()
    data_dir.mkdir()
    # Při běhu Publisheru nesmí výsledek bezpečnostního testu záviset na
    # pokusném souboru `php` v PATH. Necháváme beze změny skutečné tělo
    # `docker exec … sh -c` a nahrazujeme POUZE načtení cesty z konfigurace
    # testovací proměnnou prostředí. Kontrola syntaxe původního těla proběhla
    # výše. Produkční skript tím není ovlivněn.
    php_read = [line for line in nested.splitlines()
                if line.strip().startswith('data_dir=$(php -r ')]
    must(len(php_read) == 1 and '/var/www/html/config/config.php' in php_read[0],
         'Nelze bezpečně identifikovat načtení Nextcloud datadirectory.')
    must('HC_TEST_DATADIR' not in nested,
         'Testovací proměnná se nesmí dostat do produkčního shellu.')

    def app(folder, declared):
        directory = app_root / folder / 'appinfo'
        directory.mkdir(parents=True)
        (directory / 'info.xml').write_text('<info><id>' + declared + '</id></info>')

    app('hc_gridsight', 'hc_gridsight')
    app('hc_gridsight_old', 'hc_gridsight')
    app('hc_familytree', 'hc_familytree')
    app('hc_familytree_old', 'hc_familytree')
    app('hc_weather_wrong', 'hc_weather')
    (app_root / 'unrelated').mkdir()

    must('root=/var/www/html/custom_apps' in nested,
         'Změnila se cesta custom_apps bez úpravy testu.')
    # V testu nahraď pouze dvě okolní závislosti: kořen simulovaného
    # custom_apps a čtení datadirectory přes PHP. Samotný přesunovací
    # algoritmus, bezpečnostní podmínky a příkazy mv zůstávají identické.
    mocked = nested.replace('root=/var/www/html/custom_apps',
                            'root=' + str(app_root), 1)
    mocked = mocked.replace(php_read[0],
                            '            data_dir=$HC_TEST_DATADIR', 1)
    must('data_dir=$(php -r ' not in mocked,
         'Neúplné odpojení testovacího PHP příkazu.')
    result = execute(['/bin/sh', '-n'], input_text=mocked)
    must(result.returncode == 0,
         'Neplatná syntaxe testovacího shellu: ' + result.stderr)

    def run_case():
        result = execute(['/bin/sh', '-c', mocked, 'sh', 'hc_gridsight'], env=env)
        return result

    env['HC_TEST_DATADIR'] = str(data_dir)
    result = run_case()
    must(result.returncode == 0,
         'Simulace přesunu selhala (exit ' + str(result.returncode)
         + '), stdout=' + repr(result.stdout) + ', stderr=' + repr(result.stderr))

    must((app_root / 'hc_gridsight').is_dir(), 'Zmizel živý GridSight.')
    must(not (app_root / 'hc_gridsight_old').exists(), 'Duplicitní GridSight zůstal.')
    must((app_root / 'hc_familytree_old').is_dir(), 'Instalátor změnil cizí FamilyTree.')
    must((app_root / 'hc_weather_wrong').is_dir(), 'Instalátor změnil cizí Weather.')
    must((app_root / 'unrelated').is_dir(), 'Instalátor změnil cizí adresář.')
    quarantine = data_dir / 'hc-core-deployment-backups' / 'custom-apps-quarantine'
    moves = list(quarantine.glob('*'))
    must(len(moves) == 1 and moves[0].name.startswith('hc_gridsight.'),
         'Karanténa obsahuje chybný počet nebo jinou aplikaci.')
    must((moves[0] / 'appinfo' / 'info.xml').is_file(),
         'Přesunutá kopie GridSight není kompletní.')

    app('hc_gridsight_old', 'hc_gridsight')
    env['HC_TEST_DATADIR'] = str(app_root / 'forbidden')
    result = run_case()
    must(result.returncode != 0, 'Zálohování pod custom_apps nebylo odmítnuto.')
    must((app_root / 'hc_gridsight_old').is_dir(),
         'Při nepovolené záloze došlo ke změně souborů.')

    env['HC_TEST_DATADIR'] = ''
    result = run_case()
    must(result.returncode != 0, 'Chybějící data directory nebylo odmítnuto.')
    must((app_root / 'hc_gridsight_old').is_dir(),
         'Při chybějící data directory došlo ke změně souborů.')

print('PASS: original docker shell syntax; invalid for-loop rejected; simulated own-app-only quarantine; unsafe paths rejected')
