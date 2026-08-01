<?php
/**
 * Pie común del panel de gestión.
 *
 * Los datos de contacto son marcadores: sustitúyelos por los del gimnasio
 * (dirección, teléfono, email y redes reales). El nombre sale de APP_NOMBRE
 * en el .env, así que no hace falta tocarlo aquí.
 */
$nombrePie = defined('APP_NOMBRE') ? APP_NOMBRE : 'Gimnasio';
?>
<footer class="bg-[#111318] text-white pt-10 pb-6 px-6 md:px-12 w-full">
    <div class="grid grid-cols-1 md:grid-cols-4 gap-8 md:gap-6 max-w-7xl mx-auto text-sm items-start">

        <div class="flex flex-col items-center md:items-start text-center md:text-left space-y-1">
            <h2 class="font-bold mb-3 text-base uppercase tracking-wider text-white/90">Contacto</h2>
            <span class="text-white/90 font-bold"><?= htmlspecialchars($nombrePie, ENT_QUOTES, 'UTF-8') ?></span>
            <span class="text-white/80">C/ Dirección del gimnasio, 0</span>
            <span class="text-white/80">00000 – Localidad</span>
            <a class="hover:text-neutral-300 transition-colors duration-200" href="tel:+34000000000">Tlfno.: 000 000 000</a>
            <a class="hover:text-neutral-300 transition-colors duration-200 break-all underline decoration-white/30 hover:decoration-white"
                href="mailto:info@ejemplo.es">info@ejemplo.es</a>
        </div>

        <div class="flex flex-col items-center md:items-start text-center md:text-left space-y-1">
            <h2 class="font-bold mb-3 text-base uppercase tracking-wider text-white/90">Horario</h2>
            <span class="text-white/80">Lunes a viernes: 7:00 – 22:00</span>
            <span class="text-white/80">Sábados: 9:00 – 14:00</span>
            <span class="text-white/80">Domingos y festivos: cerrado</span>
        </div>

        <div class="flex flex-col items-center md:items-start text-center md:text-left space-y-1">
            <h2 class="font-bold mb-3 text-base uppercase tracking-wider text-white/90">Información</h2>
            <!--
                PENDIENTE: aviso legal y política de protección de datos.
                La página anterior se eliminó porque declaraba responsable del
                tratamiento al ayuntamiento del que venía este proyecto, lo cual
                era incorrecto. Prefiero no enlazar nada antes que enlazar un
                texto legal equivocado o un enlace roto.
            -->
            <span class="text-white/50 text-xs">Aviso legal y protección de datos: pendiente</span>
        </div>

        <div class="flex flex-col items-center justify-start text-center space-y-3">
            <h2 class="font-bold mb-1 text-base uppercase tracking-wider text-white/90 w-full">Síguenos</h2>
            <div class="flex gap-4 justify-center items-center flex-wrap pt-1">
                <a href="#" class="hover:opacity-80 transition-opacity duration-200" aria-label="Instagram">
                    <img class="h-5 w-5 object-contain"
                        src="https://cdn.jsdelivr.net/npm/simple-icons@v11/icons/instagram.svg"
                        style="filter: invert(1);" alt="Instagram">
                </a>
                <a href="#" class="hover:opacity-80 transition-opacity duration-200" aria-label="Facebook">
                    <img class="h-5 w-5 object-contain"
                        src="https://cdn.jsdelivr.net/npm/simple-icons@v11/icons/facebook.svg"
                        style="filter: invert(1);" alt="Facebook">
                </a>
                <a href="#" class="hover:opacity-80 transition-opacity duration-200" aria-label="WhatsApp">
                    <img class="h-5 w-5 object-contain"
                        src="https://cdn.jsdelivr.net/npm/simple-icons@v11/icons/whatsapp.svg"
                        style="filter: invert(1);" alt="WhatsApp">
                </a>
            </div>
        </div>

    </div>

    <div class="mt-8 border-t border-white/20 pt-4 text-center text-xs text-white/70">
        © <?= date('Y') ?> <?= htmlspecialchars($nombrePie, ENT_QUOTES, 'UTF-8') ?> — Panel de gestión
    </div>
</footer>

</body>

</html>
