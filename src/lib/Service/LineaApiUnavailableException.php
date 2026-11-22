<?php

declare(strict_types=1);

namespace OCA\HcGridSight\Service;

use RuntimeException;

/** Dočasná nedostupnost LINEA/Node-RED; odlišujeme od vadného API a dat. */
class LineaApiUnavailableException extends RuntimeException {
}
