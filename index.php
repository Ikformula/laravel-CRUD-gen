<?php
// index.php
// This page both creates new CRUD projects and (if a "project" query parameter is present)
// loads an existing entity's details (from entity_config.json) to pre-populate the generation form.

$editData = null;
if (isset($_GET['project'])) {
    $projectDir = $_GET['project']; // e.g.: projects/YourModel - YourEntity 20251008_142510
    $jsonFile = __DIR__ . '/projects/' . $projectDir . '/entity_config.json';
    if (file_exists($jsonFile)) {
        $editData = json_decode(file_get_contents($jsonFile), true);
    }
}

// Load all existing projects for listing.
$entities = [];
$projectsDir = __DIR__ . '/projects';
if (is_dir($projectsDir)) {
    $dirs = scandir($projectsDir);
    foreach ($dirs as $dir) {
        if ($dir === '.' || $dir === '..') continue;
        $jsonFile = $projectsDir . '/' . $dir . '/entity_config.json';
        if (file_exists($jsonFile)) {
            $config = json_decode(file_get_contents($jsonFile), true);
            if ($config) {
                $config['project_dir'] = $dir;
                $entities[] = $config;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>CRUD Boilerplate Generator</title>
  <!-- Bootstrap CSS from CDN -->
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <style>
      .fields-container { margin-top: 20px; border: 1px solid #ccc; padding: 10px; }
      .field-row { border-bottom: 1px solid #eee; padding: 10px 0; }
      .field-row:last-child { border-bottom: none; }
      .remove-field { color: red; cursor: pointer; font-size: 0.9em; }
      .foreign-options { margin-top: 10px; padding: 10px; border: 1px dashed #aaa; background: #f9f9f9; }
  </style>
</head>
<body>
<div class="container mt-4">
  <h1 class="mb-4">CRUD Boilerplate Generator</h1>

  <!-- Generation Form -->
  <div class="card mb-4">
    <div class="card-header">
        <?php if ($editData): ?>
          Edit CRUD Project <a href="index.php" class="btn btn-primary float-right">New CRUD</a>
        <?php else: ?>
          Create New CRUD Project
        <?php endif; ?>
    </div>
    <div class="card-body">
      <!-- The form action still points to generate.php (you might adjust this later to handle updates) -->
      <form id="crudForm" action="generate.php" method="POST">
        <!-- Model Name -->
        <div class="form-group">
          <label for="model_name">Model Name:</label>
          <input type="text" id="model_name" name="model_name" class="form-control" required
                 value="<?= $editData ? htmlspecialchars($editData['model_name']) : '' ?>">
        </div>
        <!-- Table Name -->
        <div class="form-group">
          <label for="table_name">Table Name:</label>
          <input type="text" id="table_name" name="table_name" class="form-control" required
                 value="<?= $editData ? htmlspecialchars($editData['table_name']) : '' ?>">
        </div>
        <!-- Entity Short Name: copies Table Name exactly -->
        <div class="form-group">
          <label for="entity_short_name">Entity Short Name:</label>
          <input type="text" id="entity_short_name" name="entity_short_name" class="form-control" required
                 value="<?= $editData ? htmlspecialchars($editData['entity_short_name']) : '' ?>">
        </div>

        <h2>Fields/Columns</h2>
        <div id="fieldsContainer" class="fields-container"></div>
        <button type="button" id="addField" class="btn btn-secondary mb-3">Add Field</button>
        <br>

        <!-- somewhere above the Fields/Columns section -->
        <div class="form-group form-check">
          <input type="checkbox" class="form-check-input" id="one_page" name="one_page">
          <label class="form-check-label" for="one_page">One‑Page CRUD</label>
        </div>

        <button type="submit" class="btn btn-primary">
            <?php if ($editData): ?>Update<?php else: ?>Generate<?php endif; ?>
        </button>
      </form>
    </div>
  </div>

  <!-- Existing Entities Listing -->
  <div class="card">
    <div class="card-header">Existing CRUD Projects</div>
    <div class="card-body">
        <?php if (count($entities) > 0): ?>
          <table class="table table-bordered">
            <thead>
            <tr>
              <th>#</th>
              <th>Model Name</th>
              <th>Table Name</th>
              <th>Entity Display Name</th>
              <th>Entity Short Name</th>
              <th>Created At</th>
              <th>Action</th>
            </tr>
            </thead>
            <tbody>
            <?php $counter = 1; ?>
            <?php foreach ($entities as $entity): ?>
              <tr>
                <td><?= $counter++ ?></td>
                <td><?= htmlspecialchars($entity['model_name']) ?></td>
                <td><?= htmlspecialchars($entity['table_name']) ?></td>
                <td><?= htmlspecialchars($entity['entity_display_title']) ?></td>
                <td><?= htmlspecialchars($entity['entity_short_name']) ?></td>
                <td><?= htmlspecialchars($entity['created_at']) ?></td>
                <td>
                  <!-- Clicking Edit reloads this file with query param for editing -->
                  <a href="?project=<?= urlencode($entity['project_dir']) ?>" class="btn btn-sm btn-primary">Edit</a>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php else: ?>
          <p>No projects found.</p>
        <?php endif; ?>
    </div>
  </div>
</div>

<!-- Include jQuery and Bootstrap JS from CDN -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
<script>
  // When the Table Name field changes, copy its value exactly into the Entity Short Name.
  document.getElementById('table_name').addEventListener('input', function() {
    document.getElementById('entity_short_name').value = this.value;
  });

  // Data type dropdown options.
  const dataTypes = [
    { value: 'short_text', label: 'Short Text – VARCHAR(191)' },
    { value: 'long_text', label: 'Long Text – TEXT' },
    { value: 'rich_text', label: 'Rich Text – TEXT with CKEditor' },
    { value: 'integer', label: 'Integer Number – BIGINT(12)' },
    { value: 'money', label: 'Money Number – VARCHAR(20) with step 0.01' },
    { value: 'date', label: 'Date – DATE' },
    { value: 'datetime', label: 'DateTime – DATETIME' },
    { value: 'boolean', label: 'Boolean – TINYINT(1)' },
    { value: 'select', label: 'Ordinary Select Dropdown – VARCHAR(191)' },
    { value: 'foreign', label: 'Foreign Key Dropdown – BIGINT(20)' },
    { value: 'radio', label: 'Radio – VARCHAR(191)' }
  ];

  let fieldCount = 0;
  // If editing an entity, load the fields data from PHP into JS.
  <?php if ($editData && isset($editData['fields'])): ?>
  const prepopulatedFields = <?= json_encode(array_values($editData['fields'])) ?>;
  <?php else: ?>
  const prepopulatedFields = [];
  <?php endif; ?>

  function addFieldRow(prepopulated = null) {
    let currentIndex = fieldCount;
    const container = document.getElementById('fieldsContainer');
    const row = document.createElement('div');
    row.className = 'field-row';
    row.innerHTML = `
            <div class="form-group">
                <label>Column Name:</label>
                <input type="text" name="fields[${currentIndex}][column_name]" class="form-control" required>
            </div>
            <div class="form-group">
                <label>View Name:</label>
                <input type="text" name="fields[${currentIndex}][view_name]" class="form-control" required data-manual="false">
            </div>
            <div class="form-group">
                <label>Data Type:</label>
                <select name="fields[${currentIndex}][data_type]" class="form-control data-type-select" required>
                    ${dataTypes.map(dt => `<option value="${dt.value}" ${dt.value === 'short_text' ? 'selected' : ''}>${dt.label}</option>`).join('')}
                </select>
            </div>
            <div class="foreign-options" style="display:none;">
                <div class="form-group">
                    <label>Related Table Name:</label>
                    <input type="text" name="fields[${currentIndex}][related_table]" class="form-control">
                </div>
                <div class="form-group">
                    <label>Related Model Name:</label>
                    <input type="text" name="fields[${currentIndex}][related_model]" class="form-control">
                </div>
                <div class="form-group">
                    <label>Display Column Name:</label>
                    <input type="text" name="fields[${currentIndex}][display_column]" class="form-control">
                </div>
            </div>
            <span class="remove-field text-danger" style="cursor:pointer;">Remove Field</span>
        `;
    container.appendChild(row);

    // Set up auto-population of View Name (in title case) if not manually changed.
    const columnNameInput = row.querySelector(`input[name="fields[${currentIndex}][column_name]"]`);
    const viewNameInput = row.querySelector(`input[name="fields[${currentIndex}][view_name]"]`);
    columnNameInput.addEventListener('input', function() {
      if (viewNameInput.dataset.manual !== "true") {
        let parts = this.value.split('_');
        for (let i = 0; i < parts.length; i++) {
          if (parts[i].length > 0) {
            parts[i] = parts[i].charAt(0).toUpperCase() + parts[i].slice(1);
          }
        }
        viewNameInput.value = parts.join(' ');
      }
    });
    viewNameInput.addEventListener('input', function() {
      this.dataset.manual = "true";
    });

    // Show or hide foreign key options.
    const dataTypeSelect = row.querySelector('.data-type-select');
    const foreignOptionsDiv = row.querySelector('.foreign-options');
    dataTypeSelect.addEventListener('change', function() {
      foreignOptionsDiv.style.display = (this.value === 'foreign') ? 'block' : 'none';
    });

    // Remove the current field row.
    row.querySelector('.remove-field').addEventListener('click', function() {
      container.removeChild(row);
    });

    // If prepopulated data was provided, fill in the inputs.
    if (prepopulated) {
      columnNameInput.value = prepopulated.column_name || '';
      viewNameInput.value = prepopulated.view_name || '';
      dataTypeSelect.value = prepopulated.data_type || '';
      // Show foreign options if applicable.
      if (prepopulated.data_type === 'foreign') {
        foreignOptionsDiv.style.display = 'block';
        row.querySelector(`input[name="fields[${currentIndex}][related_table]"]`).value = prepopulated.related_table || '';
        row.querySelector(`input[name="fields[${currentIndex}][related_model]"]`).value = prepopulated.related_model || '';
        row.querySelector(`input[name="fields[${currentIndex}][display_column]"]`).value = prepopulated.display_column || '';
      }
    }

    fieldCount++;
  }

  // Add fields: if editing, build rows with prepopulated Fields; otherwise, add one empty row.
  if (prepopulatedFields.length > 0) {
    prepopulatedFields.forEach(field => addFieldRow(field));
  } else {
    addFieldRow();
  }

  // Handler for adding another field row manually.
  document.getElementById('addField').addEventListener('click', addFieldRow);
</script>
</body>
</html>
