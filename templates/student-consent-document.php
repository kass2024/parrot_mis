<?php
$field = static function (string $name, string $type, int $length) use ($copy, $details, $editable, $h): void {
    $label = $copy['labels'][$name];
    $value = (string) ($details[$name] ?? '');
    if ($editable) {
        echo '<input class="consent-underline consent-input" type="', $h($type), '" name="', $h($name),
            '" id="consent-', $h($name), '" aria-label="', $h($label), '" value="', $h($value),
            '" maxlength="', $length, '" required>';
        return;
    }
    echo '<span class="consent-underline">', $value !== '' ? $h($value) : '&nbsp;', '</span>';
};
?>
<article class="consent-document">
  <img class="consent-letterhead" src="<?= $h($letterhead) ?>" alt="Parrot Canada Visa Consultant Company">
  <h1><?= $h($copy['title']) ?></h1>
  <h2><?= $h($copy['information']) ?></h2>
  <?php foreach (student_consent_fields() as $name => [$type, $length]): ?>
    <?php if ($name === 'country') { continue; } ?>
    <p class="consent-line">
      <label for="consent-<?= $h($name) ?>"><?= $h($copy['labels'][$name]) ?>:</label>
      <?php $field($name, $type, $length); ?>
    </p>
  <?php endforeach; ?>

  <h2><?= $h($copy['consent']) ?></h2>
  <p><?= $h($copy['appointment']) ?></p>
  <p><?= $h($copy['authorize']) ?></p>
  <ul>
    <?php foreach ($copy['permissions'] as $permission): ?><li><?= $h($permission) ?></li><?php endforeach; ?>
  </ul>
  <p><?= $h($copy['privacy']) ?></p>
  <p><?= $h($copy['withdrawal']) ?></p>

  <h2><?= $h($copy['signature_title']) ?></h2>
  <p><?= $h($copy['declaration']) ?></p>
  <p class="consent-line"><?= $h($copy['signature_name']) ?>:
    <span class="consent-underline" id="consent-signature-name"><?= !empty($details['student_name']) ? $h($details['student_name']) : '&nbsp;' ?></span>
  </p>
  <div class="consent-line">
    <?= $h($copy['signature']) ?>:
    <?php if ($editable): ?>
      <canvas id="consent-signature" width="600" height="140" aria-label="<?= $h($copy['signature']) ?>"></canvas>
      <button type="button" id="consent-clear"><?= $h($copy['clear']) ?></button>
    <?php elseif ($signature !== ''): ?>
      <img class="consent-signature-image" src="<?= $h($signature) ?>" alt="<?= $h($copy['signature']) ?>">
    <?php endif; ?>
  </div>
  <p class="consent-line"><?= $h($copy['date_country']) ?>:
    <time datetime="<?= $h($date) ?>"><?= $h($date) ?></time> · <?php $field('country', 'text', 100); ?>
  </p>

  <div class="consent-agency">
    <h2><?= $h($copy['agency']) ?></h2>
    <p class="consent-line"><?= $h($copy['representative']) ?>: <?= $h(AGENT_CONTRACT_COMPANY_SIGNER) ?></p>
    <p class="consent-line"><?= $h($copy['agency_signature']) ?>:
      <time datetime="<?= $h($date) ?>"><?= $h($date) ?></time>
    </p>
    <div class="consent-agency-images">
      <img src="<?= $h($agencySignature) ?>" alt="<?= $h($copy['representative']) ?>">
      <img class="consent-agency-stamp" src="<?= $h($agencyStamp) ?>" alt="<?= $h($copy['agency_stamp']) ?>">
    </div>
  </div>
  <img class="consent-letter-footer" src="<?= $h($letterFooter) ?>" alt="Providing Best Study and Travel Abroad Services">
</article>
