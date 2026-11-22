<?php
// Shared by the French signing page and its signed PDF.
$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$dateText = static function (string $value): string {
    return agent_contract_senegal_date($value) ? substr($value, 8, 2) . '/' . substr($value, 5, 2) . '/' . substr($value, 0, 4) : $value;
};
$value = static fn(string $id): string => (string) ($senegalDocument[$id] ?? '');
$field = static function (string $id, string $label, string $type = 'text', int $limit = 0, bool $required = true)
    use ($escape, $dateText, $value, $senegalEditable): void {
    $text = $value($id);
    if (!$senegalEditable) {
        $text = $type === 'date' ? $dateText($text) : $text;
        echo '<span class="senegal-value">', $escape($text !== '' ? $text : '___________________'), '</span>';
        return;
    }
    $attributes = ' id="' . $escape($id) . '" class="senegal-field" aria-label="' . $escape($label) . '"'
        . ($limit > 0 ? ' maxlength="' . $limit . '"' : '') . ($required ? ' required' : '');
    if (isset(agent_contract_senegal_fields()[$id])) {
        $attributes .= ' data-senegal-required';
    }
    if ($type === 'textarea') {
        echo '<textarea rows="2"', $attributes, '>', $escape($text), '</textarea>';
    } else {
        echo '<input type="', $escape($type), '"', $attributes, ' value="', $escape($text), '">';
    }
};
$agentRows = [
    ['agent_name', "Nom complet de l'Agent", 'text', 255],
    ['date_of_birth', 'Date de naissance', 'date', 0],
    ['nationality', 'Nationalité', 'text', 100],
    ['national_id', 'CNI ou passeport', 'text', 64],
    ['agent_address', 'Adresse au Sénégal', 'textarea', 500],
    ['region', 'Ville ou région', 'text', 100],
    ['agent_email', 'Courriel', 'email', 190],
    ['agent_phone', 'Téléphone / WhatsApp', 'tel', 64],
    ['payment_account', "Compte de paiement au nom de l'Agent", 'text', 190],
];
?>
<article class="senegal-document">
  <div class="senegal-running-header">PARROT CANADA VISA CONSULTANT CO.LTD | AGENT INDIVIDUEL — SÉNÉGAL</div>
  <h1 class="senegal-company">PARROT CANADA VISA CONSULTANT CO.LTD</h1>
  <h2 class="senegal-title">CONTRAT SIMPLE D'AGENT INDIVIDUEL<br>DE RÉFÉRENCEMENT — SÉNÉGAL</h2>
  <p class="senegal-subtitle">Pour les personnes qui ne possèdent pas de société enregistrée et souhaitent référer des clients</p>
  <p class="senegal-reference">
    <strong>Référence :</strong> <?= $escape($value('reference')) ?><br>
    <strong>Date d'entrée en vigueur :</strong>
    <?php if ($senegalEditable && $value('effective_date') !== ''): ?>
      <input type="hidden" id="effective_date" value="<?= $escape($value('effective_date')) ?>">
      <?= $escape($dateText($value('effective_date'))) ?>
    <?php else: ?>
      <?php $field('effective_date', "Date d'entrée en vigueur", 'date', 0, false); ?>
    <?php endif; ?>
  </p>

  <h3 class="section-title">1. PARTIES</h3>
  <table class="senegal-table senegal-parties">
    <tr><th scope="row">Société</th><td>PARROT CANADA VISA CONSULTANT CO.LTD (la « Société »)</td></tr>
    <tr><th scope="row">Courriel officiel</th><td>infos@visaconsultantcanada.com</td></tr>
    <tr><th scope="row">Représentant autorisé</th><td><?= $escape(AGENT_CONTRACT_COMPANY_SIGNER) ?></td></tr>
  </table>
  <table class="senegal-table senegal-parties">
    <?php foreach ($agentRows as [$id, $label, $type, $limit]): ?>
    <tr>
      <th scope="row"><?= $escape($label) ?></th>
      <td><?php $field($id, $label, $type, $limit); ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <p>La Société et l'Agent sont ensemble appelés les « Parties ». L'Agent signe en son nom personnel et non au nom d'une société.</p>

  <?php require __DIR__ . '/agent-contract-senegal-fr.html'; ?>

  <h3 class="section-title">SIGNATURES</h3>
  <table class="senegal-table senegal-signatures">
    <thead>
      <tr><th scope="col">POUR PARROT CANADA VISA CONSULTANT CO.LTD</th><th scope="col">AGENT INDIVIDUEL — SÉNÉGAL</th></tr>
    </thead>
    <tbody>
      <tr>
        <td>Représentant autorisé : <?= $escape(AGENT_CONTRACT_COMPANY_SIGNER) ?></td>
        <td>Nom complet : <?php $field('sig_agent_name', 'Nom complet pour la signature', 'text', 255); ?></td>
      </tr>
      <tr>
        <td>Fonction : <?= $escape(AGENT_CONTRACT_COMPANY_SIGNER_TITLE_FR) ?></td>
        <td>CNI ou passeport : <span id="senegal-signature-id"><?= $escape($value('national_id') ?: '___________________') ?></span></td>
      </tr>
      <tr>
        <td>
          Signature :
          <div class="senegal-signature-images">
            <img src="<?= $escape($value('company_signature')) ?>" alt="Signature de la Société">
            <img class="senegal-stamp" src="<?= $escape($value('company_stamp')) ?>" alt="Cachet de la Société">
          </div>
        </td>
        <td>
          Signature :
          <?php if ($senegalEditable): ?>
            <canvas class="signature-canvas senegal-canvas" aria-label="Tracez votre signature"></canvas>
          <?php elseif ($value('agent_signature') !== ''): ?>
            <div class="senegal-signature-images"><img src="<?= $escape($value('agent_signature')) ?>" alt="Signature de l'Agent"></div>
          <?php endif; ?>
        </td>
      </tr>
      <tr>
        <td>
          Date : <?= $escape($value('effective_date') !== '' ? $dateText($value('effective_date')) : '___________________') ?><br>
          Lieu :
          <?php if ($senegalEditable && $value('company_signing_place') !== ''): ?>
            <?= $escape($value('company_signing_place')) ?>
          <?php else: ?>
            <?php $field('company_signing_place', 'Lieu de signature de la Société (facultatif)', 'text', 100, false); ?>
          <?php endif; ?>
        </td>
        <td>
          Date : <?php $field('sig_signed_date', 'Date de signature', 'date'); ?><br>
          Lieu : <?php $field('signing_place', "Lieu de signature de l'Agent", 'text', 100); ?>
        </td>
      </tr>
    </tbody>
  </table>
</article>
