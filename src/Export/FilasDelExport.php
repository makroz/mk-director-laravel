<?php

declare(strict_types=1);

namespace Mk\Director\Export;

/**
 * El buzón por el que el controller le pasa las filas al job.
 *
 * ## 🔴 Por qué existe: el snapshot no entraba
 *
 * El motor original guardaba **la lista entera** en una columna JSON del
 * reporte, y el job la volvía a leer de ahí. Funcionó mientras los módulos
 * fueron chicos. El primero lo bastante grande tocó el techo, y no por poco —
 * medido sobre un listado de accesos real:
 *
 * | | |
 * |---|---|
 * | filas a exportar | **198.004** |
 * | el snapshot serializado | **~422 MB de JSON** |
 * | `memory_limit` de PHP | **128 MB** |
 * | `max_allowed_packet` de MariaDB | **16 MB** |
 *
 * O sea **26× los dos techos**. El request se quedaba sin memoria antes de
 * responder y el browser mostraba "Failed to fetch" — que parece un problema
 * de red y no lo es.
 *
 * 🔴 **Y el snapshot se calculaba DOS VECES**: una en el request al apretar el
 * botón, y otra adentro del job, que la pisaba. La del request era puro
 * desperdicio —nadie la leía— y era, además, la única que corría con el
 * `memory_limit` chico y un usuario esperando del otro lado.
 *
 * ## Cómo funciona ahora
 *
 * El request sólo encola: guarda los filtros y despacha. El job re-ejecuta el
 * controller —cosa que ya hacía— y las filas le llegan **por acá, en
 * memoria**, sin pasar por la base. El trabajo pesado queda en el worker, que
 * arranca con el `memory_limit` del motor.
 *
 * ## ⚠️ Por qué un objeto del container y no una propiedad estática
 *
 * Una estática se comparte entre todo lo que corra en el proceso. El worker
 * atiende un job tras otro sin reiniciar, y `queue:work --once` o un test con
 * la cola sincrónica corren varios en la misma vida del proceso: las filas de
 * un reporte se le aparecerían al siguiente. Acá el job lo resuelve del
 * container y lo VACÍA antes de pedir, así que lo que encuentra es de este
 * pedido o no hay nada.
 */
final class FilasDelExport
{
    /** @var iterable<mixed>|null */
    private ?iterable $filas = null;

    private bool $entregadas = false;

    /** El job, antes de re-ejecutar el controller. */
    public function vaciar(): void
    {
        $this->filas = null;
        $this->entregadas = false;
    }

    /** El controller, cuando corre adentro del job. */
    public function dejar(iterable $filas): void
    {
        $this->filas = $filas;
        $this->entregadas = true;
    }

    /**
     * 🔴 `hayFilas()` y no `filas() !== []`. Una lista legítimamente VACÍA —un
     * filtro que no matcheó nada— es distinta de que el controller no haya
     * pasado por acá. La primera tiene que dar un archivo con el encabezado y
     * sin renglones; la segunda es un error de cableado.
     *
     * Confundirlas es exactamente cómo el motor llegó a marcar `completed`
     * reportes vacíos: un PDF con encabezado y ninguna fila, que dice que
     * salió bien.
     */
    public function hayFilas(): bool
    {
        return $this->entregadas;
    }

    /** @return iterable<mixed> */
    public function filas(): iterable
    {
        return $this->filas ?? [];
    }
}
