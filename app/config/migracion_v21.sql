-- Fase 4: integridad, búsquedas de rate-limit e idempotencia de operaciones críticas.
ALTER TABLE producto
    ADD CONSTRAINT chk_producto_stock_no_negativo CHECK (stock >= 0),
    ADD CONSTRAINT chk_producto_stock_minimo_no_negativo CHECK (stock_minimo >= 0),
    ADD CONSTRAINT chk_producto_precio_no_negativo CHECK (precio >= 0);

ALTER TABLE venta
    ADD COLUMN idempotency_key CHAR(36) NULL AFTER numero,
    ADD UNIQUE KEY uq_venta_idempotencia (id_gimnasio, idempotency_key),
    ADD CONSTRAINT chk_venta_total_no_negativo CHECK (total >= 0);

ALTER TABLE socio_membresia
    ADD COLUMN idempotency_key CHAR(36) NULL AFTER origen,
    ADD UNIQUE KEY uq_membresia_idempotencia (id_gimnasio, idempotency_key),
    ADD CONSTRAINT chk_membresia_importes_no_negativos CHECK (precio_pagado >= 0 AND precio_suplemento >= 0);

ALTER TABLE remesa
    ADD COLUMN idempotency_key CHAR(36) NULL AFTER id_usuario_creador,
    ADD UNIQUE KEY uq_remesa_idempotencia (id_gimnasio, idempotency_key),
    ADD CONSTRAINT chk_remesa_importe_no_negativo CHECK (importe_total >= 0);

ALTER TABLE intentos_login
    ADD INDEX idx_intentos_usuario_fecha (usuario, fecha_intento),
    ADD INDEX idx_intentos_ip_fecha (ip_address, fecha_intento);

ALTER TABLE intentos_gimnasio
    ADD INDEX idx_intentos_gym_email_fecha (email, fecha_intento),
    ADD INDEX idx_intentos_gym_ip_fecha (ip_address, fecha_intento);
