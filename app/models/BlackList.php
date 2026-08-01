<?php

class Blacklist {
    private $db;
    private $max_intentos  = 5;
    private $tiempo_bloqueo = 900;

    public function __construct($conexion) {
        $this->db = $conexion;
    }

    public function registrarIntentoFallido($ip, $usuario = null) {
        $stmt = $this->db->prepare(
            "INSERT INTO intentos_login (ip_address, usuario, fecha_intento)
             VALUES (?, ?, NOW())"
        );
        $stmt->execute([$ip, $usuario]);
    }

    public function estaBloqueado($ip, $usuario = null) {
        $this->limpiarIntentosAntiguos($ip, $usuario);

        if ($usuario) {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) as intentos FROM intentos_login
                 WHERE usuario = ?
                 AND fecha_intento > DATE_SUB(NOW(), INTERVAL ? SECOND)"
            );
            $stmt->execute([$usuario, $this->tiempo_bloqueo]);
        } else {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) as intentos FROM intentos_login
                 WHERE ip_address = ?
                 AND fecha_intento > DATE_SUB(NOW(), INTERVAL ? SECOND)"
            );
            $stmt->execute([$ip, $this->tiempo_bloqueo]);
        }

        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
        return $resultado['intentos'] >= $this->max_intentos;
    }

    public function getIntentosRestantes($ip, $usuario = null) {
        if ($usuario) {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) as intentos FROM intentos_login
                 WHERE usuario = ?
                 AND fecha_intento > DATE_SUB(NOW(), INTERVAL ? SECOND)"
            );
            $stmt->execute([$usuario, $this->tiempo_bloqueo]);
        } else {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) as intentos FROM intentos_login
                 WHERE ip_address = ?
                 AND fecha_intento > DATE_SUB(NOW(), INTERVAL ? SECOND)"
            );
            $stmt->execute([$ip, $this->tiempo_bloqueo]);
        }

        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
        return max(0, $this->max_intentos - $resultado['intentos']);
    }

    public function limpiarIntentos($ip, $usuario = null) {
        if ($usuario) {
            $stmt = $this->db->prepare("DELETE FROM intentos_login WHERE usuario = ?");
            $stmt->execute([$usuario]);
        } else {
            $stmt = $this->db->prepare("DELETE FROM intentos_login WHERE ip_address = ?");
            $stmt->execute([$ip]);
        }
    }

    private function limpiarIntentosAntiguos($ip, $usuario = null) {
        if ($usuario) {
            $stmt = $this->db->prepare(
                "DELETE FROM intentos_login WHERE usuario = ?
                 AND fecha_intento < DATE_SUB(NOW(), INTERVAL ? SECOND)"
            );
            $stmt->execute([$usuario, $this->tiempo_bloqueo]);
        } else {
            $stmt = $this->db->prepare(
                "DELETE FROM intentos_login WHERE ip_address = ?
                 AND fecha_intento < DATE_SUB(NOW(), INTERVAL ? SECOND)"
            );
            $stmt->execute([$ip, $this->tiempo_bloqueo]);
        }
    }
}
