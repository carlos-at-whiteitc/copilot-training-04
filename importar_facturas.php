<?php
// importar_facturas.php
// Importa facturas desde un archivo CSV y las guarda en la base de datos.
// Desarrollado originalmente en 2019. Modificado varias veces sin refactorizar.

function importarFacturasDesdeCSV(string $rutaArchivo, mysqli $db, string $empresaId): array
{
    $resultados = [];
    $errores    = [];
    $procesadas = 0;
    $omitidas   = 0;

    // Verificar que el archivo existe
    if (!file_exists($rutaArchivo)) {
        return ['ok' => false, 'msg' => 'El archivo no existe: ' . $rutaArchivo];
    }

    // Abrir el archivo
    $f = fopen($rutaArchivo, 'r');
    if ($f === false) {
        return ['ok' => false, 'msg' => 'No se pudo abrir el archivo'];
    }

    // Saltar encabezado
    fgetcsv($f);

    while (($fila = fgetcsv($f, 1000, ',')) !== false) {

        // Validar que la fila tenga las columnas necesarias
        if (count($fila) < 8) {
            $errores[] = 'Fila incompleta, se omite: ' . implode(',', $fila);
            $omitidas++;
            continue;
        }

        $uuid       = trim($fila[0]);
        $rfc_emisor = trim($fila[1]);
        $rfc_recept = trim($fila[2]);
        $fecha      = trim($fila[3]);
        $subtotal   = trim($fila[4]);
        $iva        = trim($fila[5]);
        $total      = trim($fila[6]);
        $estatus    = trim($fila[7]);
        $moneda     = isset($fila[8]) ? trim($fila[8]) : 'MXN';

        // Validar UUID (formato CFDI)
        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $uuid)) {
            $errores[] = "UUID inválido: $uuid";
            $omitidas++;
            continue;
        }

        // Validar RFC emisor (patrón SAT México)
        if (!preg_match('/^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/', $rfc_emisor)) {
            $errores[] = "RFC emisor inválido: $rfc_emisor";
            $omitidas++;
            continue;
        }

        // Validar RFC receptor
        if (!preg_match('/^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/', $rfc_recept)) {
            $errores[] = "RFC receptor inválido: $rfc_recept";
            $omitidas++;
            continue;
        }

        // Validar fecha
        $dt = DateTime::createFromFormat('Y-m-d', $fecha);
        if (!$dt || $dt->format('Y-m-d') !== $fecha) {
            $errores[] = "Fecha inválida: $fecha en UUID $uuid";
            $omitidas++;
            continue;
        }

        // Validar montos
        if (!is_numeric($subtotal) || $subtotal < 0) {
            $errores[] = "Subtotal inválido: $subtotal en UUID $uuid";
            $omitidas++;
            continue;
        }
        if (!is_numeric($iva) || $iva < 0) {
            $errores[] = "IVA inválido: $iva en UUID $uuid";
            $omitidas++;
            continue;
        }
        if (!is_numeric($total) || $total <= 0) {
            $errores[] = "Total inválido: $total en UUID $uuid";
            $omitidas++;
            continue;
        }

        // Validar estatus
        $estatusValidos = ['vigente', 'cancelada', 'por_cancelar'];
        if (!in_array(strtolower($estatus), $estatusValidos)) {
            $errores[] = "Estatus inválido: $estatus en UUID $uuid";
            $omitidas++;
            continue;
        }

        // Verificar si ya existe en la BD
        $uuidEscapado = $db->real_escape_string($uuid);
        $check = $db->query("SELECT id FROM facturas WHERE uuid = '$uuidEscapado' AND empresa_id = '$empresaId'");
        if ($check && $check->num_rows > 0) {
            $errores[] = "UUID duplicado, se omite: $uuid";
            $omitidas++;
            continue;
        }

        // Insertar en la BD
        $rfcE  = $db->real_escape_string($rfc_emisor);
        $rfcR  = $db->real_escape_string($rfc_recept);
        $mon   = $db->real_escape_string($moneda);
        $est   = $db->real_escape_string(strtolower($estatus));
        $empId = $db->real_escape_string($empresaId);

        $sql = "INSERT INTO facturas
                    (uuid, empresa_id, rfc_emisor, rfc_receptor, fecha_emision,
                     subtotal, iva, total, moneda, estatus, fecha_importacion)
                VALUES
                    ('$uuidEscapado', '$empId', '$rfcE', '$rfcR', '$fecha',
                     $subtotal, $iva, $total, '$mon', '$est', NOW())";

        if ($db->query($sql)) {
            $resultados[] = ['uuid' => $uuid, 'total' => $total];
            $procesadas++;
        } else {
            $errores[] = "Error BD al insertar $uuid: " . $db->error;
            $omitidas++;
        }
    }

    fclose($f);

    return [
        'ok'        => true,
        'procesadas'=> $procesadas,
        'omitidas'  => $omitidas,
        'total'     => $procesadas + $omitidas,
        'errores'   => $errores,
        'detalle'   => $resultados,
    ];
}
