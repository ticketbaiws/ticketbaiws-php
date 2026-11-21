<?php

namespace TicketbaiWS;

/**
 * Gestión de la cuenta de cliente: alta y edición de empresas y licencias.
 *
 * Usa el TOKEN DE DESARROLLADOR de la cuenta (área privada) y el NIF de la
 * cuenta, no los de una empresa. Estos métodos solo existen en producción,
 * por eso el entorno por defecto aquí es "real".
 *
 *   $cuenta = new \TicketbaiWS\Cuenta($tokenDesarrollador, $nifCuenta);
 *   $r = $cuenta->crearEmpresa([... 'diputacion' => Diputacion::BIZKAIA]);
 *   // $r->getData() incluye los tokens de pruebas y de producción de la nueva empresa
 */
class Cuenta extends AbstractApi
{
    protected function entornoPorDefecto(): string
    {
        return 'real';
    }

    /**
     * POST /empresas/ — obligatorios: nombre_social, nif, direccion, poblacion,
     * provincia, cp, diputacion (ver Diputacion::*). Opcionales: id_licencia,
     * nombre_comercial, email, web, epigrafe (autónomos, CB y SC de Bizkaia),
     * spfe (bool: envío de facturas B2B a la SPFE, Ley Crea y Crece).
     *
     * @param array<string,mixed> $empresa
     */
    public function crearEmpresa(array $empresa): Response
    {
        return $this->peticion('POST', 'empresas', $empresa);
    }

    /**
     * PUT /empresas/{nif}/ — campos editables: nombre_social, nombre_comercial,
     * direccion, poblacion, provincia, cp, email, web, epigrafe, autorenovacion.
     *
     * @param array<string,mixed> $cambios
     */
    public function editarEmpresa(string $nif, array $cambios): Response
    {
        unset($cambios['nif']);
        return $this->peticion('PUT', 'empresas/' . rawurlencode($nif), $cambios);
    }

    /**
     * GET /empresas/ — filtros opcionales: id_licencia, nif.
     *
     * @param array<string,mixed> $filtros
     */
    public function listarEmpresas(array $filtros = []): Response
    {
        return $this->peticion('GET', 'empresas', $filtros);
    }

    /**
     * Activa o desactiva el envío a la SPFE (factura electrónica B2B, Ley Crea y
     * Crece). Al activarlo se envían las facturas desde ese día.
     */
    public function activarSpfe(string $nif, bool $activar = true): Response
    {
        return $this->editarEmpresa($nif, ['spfe' => $activar]);
    }

    /** POST /licencias/ — $modalidad: "mensual" o "anual". */
    public function crearLicencia(int $plan, int $cantidad, int $mesesAnos, string $modalidad): Response
    {
        return $this->peticion('POST', 'licencias', [
            'plan' => $plan,
            'cantidad' => $cantidad,
            'meses_anos' => $mesesAnos,
            'modalidad' => $modalidad,
        ]);
    }

    /** GET /licencias/ — todas, o solo $idLicencia. */
    public function listarLicencias(?int $idLicencia = null): Response
    {
        return $this->peticion('GET', 'licencias', $idLicencia !== null ? ['id_licencia' => $idLicencia] : []);
    }
}
