<?php
/**
 * @var ?string $error       mensaje a mostrar tras un intento fallido
 * @var bool    $configurada false si falta cargar ADMIN_PASSWORD_HASH
 */
?>
<div class="panel" style="max-width:400px;margin:8vh auto 0">
  <h2>Ingresar</h2>

  <?php if (!$configurada): ?>
    <div class="alerta">
      <strong>Falta configurar <span class="mono">ADMIN_PASSWORD_HASH</span></strong>
      Generá el hash con
      <span class="mono">php -r 'echo password_hash("tu-clave", PASSWORD_DEFAULT);'</span>
      y cargalo en las variables de entorno. Hasta entonces no se puede entrar.
    </div>
  <?php elseif ($error !== null): ?>
    <div class="alerta"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="post" action="/login">
    <label for="password">Contraseña</label>
    <input type="password" id="password" name="password" style="width:100%"
           autocomplete="current-password" autofocus
           <?= $configurada ? '' : 'disabled' ?>>
    <div style="margin-top:16px;display:flex;justify-content:flex-end">
      <button type="submit" class="primario" <?= $configurada ? '' : 'disabled' ?>>Entrar</button>
    </div>
  </form>
</div>
