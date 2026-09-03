<?php $trainingTab=$trainingTab??'library'; ?>
<nav aria-label="Secciones de entrenamientos" class="mt-5 flex flex-wrap gap-2">
<?php foreach(['library'=>['training_library','Biblioteca'],'templates'=>['training_templates','Plantillas'],'plans'=>['training_plans','Planes']] as $key=>[$action,$label]): ?>
    <a href="<?=APP_URL?>/index.php?action=<?=$action?>" class="inline-flex min-h-11 items-center rounded-full px-5 text-sm font-bold <?= $trainingTab===$key?'bg-[#111318] text-white':'border border-neutral-300 bg-white text-neutral-700' ?>"><?=$label?></a>
<?php endforeach; ?>
</nav>
