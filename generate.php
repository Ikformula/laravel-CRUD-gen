<?php
// generate.php

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $onePage = isset($_POST['one_page']) && $_POST['one_page'] == 'on';

    // Retrieve inputs
    $modelName = trim($_POST['model_name']);
    $tableName = trim($_POST['table_name']);
    $entityShortName = trim($_POST['entity_short_name']);
    $entityDisplayTitle = trim($_POST['entity_display_title']);

    // Filter out incomplete fields
    $fields = array_filter($_POST['fields'] ?? [], function ($field) {
        return isset($field['column_name'], $field['data_type'], $field['view_name']) &&
            trim($field['column_name']) !== '' && trim($field['data_type']) !== '';
    });

    if (empty($modelName) || empty($tableName) || empty($entityShortName) || empty($fields)) {
        die("Missing required fields.");
    }


    // Create project directory with timestamp
    $date = date('Ymd_His');
    $projectDir = "projects/{$modelName} - {$entityShortName} {$date}/";
    if (!is_dir($projectDir)) {
        mkdir($projectDir, 0777, true);
    }

    if ($onePage) {
        generateOnePageCRUD($modelName, $tableName, $entityShortName, $entityDisplayTitle, $fields, $projectDir);
    } else {
        // Define paths for generated files
        $controllerPath = $projectDir . "app/Http/Controllers/Frontend/User/{$modelName}Controller.php";
        $modelPath = $projectDir . "app/Models/{$modelName}.php";
        $migrationPath = $projectDir . "database/migrations/" . date('Y_m_d_His') . "_create_{$tableName}_table.php";
        $viewsDir = $projectDir . "resources/views/frontend/" . strtolower($entityShortName) . "/";
        $routesPath = $projectDir . "routes/" . strtolower($entityShortName) . ".php";
        $configPath = $projectDir . "entity_config.json"; // will store entity details in JSON

        // Create directories
        @mkdir(dirname($controllerPath), 0777, true);
        @mkdir(dirname($modelPath), 0777, true);
        @mkdir(dirname($migrationPath), 0777, true);
        @mkdir($viewsDir, 0777, true);
        @mkdir(dirname($routesPath), 0777, true);

        // Build fillable and relationship parts
        $fillable = [];
        $relationshipMethods = "";
        foreach ($fields as $field) {
            $col = addslashes(trim($field['column_name']));
            $fillable[] = "'{$col}'";
            if ($field['data_type'] === 'foreign') {
                $relatedModel = !empty($field['related_model']) ? trim($field['related_model']) : 'RelatedModel';
                $methodName = lcfirst($col) . "Relation";
                $relationshipMethods .= "\n    public function {$methodName}()\n    {\n";
                $relationshipMethods .= "        return \$this->belongsTo({$relatedModel}::class, '{$col}');\n";
                $relationshipMethods .= "    }\n";
            }
        }
        $fillableStr = implode(",\n        ", $fillable);

        // Generate Model
        $modelContent = "<?php

namespace App\\Models;

use Illuminate\\Database\\Eloquent\\Model;

class {$modelName} extends Model
{
    protected \$fillable = [
        {$fillableStr}
    ];
    {$relationshipMethods}
}
";
        file_put_contents($modelPath, $modelContent);

        // Generate Migration file
        $migrationContent = "<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

class Create" . ucfirst($modelName) . "Table extends Migration
{
    public function up()
    {
        Schema::create('{$tableName}', function (Blueprint \$table) {
            \$table->bigIncrements('id');
";
        foreach ($fields as $field) {
            $col = trim($field['column_name']);
            $dataType = $field['data_type'];
            switch ($dataType) {
                case 'short_text':
                    $migrationContent .= "            \$table->string('{$col}', 191);\n";
                    break;
                case 'long_text':
                case 'rich_text':
                    $migrationContent .= "            \$table->text('{$col}');\n";
                    break;
                case 'integer':
                    $migrationContent .= "            \$table->bigInteger('{$col}');\n";
                    break;
                case 'money':
                    $migrationContent .= "            \$table->string('{$col}', 20);\n";
                    break;
                case 'date':
                    $migrationContent .= "            \$table->date('{$col}');\n";
                    break;
                case 'datetime':
                    $migrationContent .= "            \$table->dateTime('{$col}');\n";
                    break;
                case 'boolean':
                    $migrationContent .= "            \$table->tinyInteger('{$col}');\n";
                    break;
                case 'select':
                case 'radio':
                    $migrationContent .= "            \$table->string('{$col}', 191);\n";
                    break;
                case 'foreign':
                    $migrationContent .= "            \$table->bigInteger('{$col}');\n";
                    if (!empty($field['related_table'])) {
                        $relatedTable = trim($field['related_table']);
                        $migrationContent .= "            \$table->foreign('{$col}')->references('id')->on('{$relatedTable}')->onDelete('cascade');\n";
                    }
                    break;
                default:
                    $migrationContent .= "            \$table->string('{$col}', 191);\n";
            }
        }
        $migrationContent .= "            \$table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('{$tableName}');
    }
}
";
        file_put_contents($migrationPath, $migrationContent);

        // Generate Controller file with flash messages (using withFlashSuccess and withErrors)
        $controllerContent = "<?php

namespace App\\Http\\Controllers\\Frontend\\User;

use App\\Http\\Controllers\\Controller;
use App\\Models\\{$modelName};
use Illuminate\\Http\\Request;

class {$modelName}Controller extends Controller
{
    public function index()
    {
        \$items = {$modelName}::all();
        return view('frontend." . strtolower($entityShortName) . ".index', compact('items'));
    }

    public function create()
    {
        return view('frontend." . strtolower($entityShortName) . ".create');
    }

    public function store(Request \$request)
    {
        try {
            {$modelName}::create(\$request->all());
            return redirect()->route('frontend." . strtolower($entityShortName) . ".index')
                ->withFlashSuccess('{$entityDisplayTitle} created successfully!');
        } catch (\\Exception \$e) {
            return redirect()->back()
                ->withErrors('Error creating {$entityDisplayTitle}: ' . \$e->getMessage());
        }
    }

    public function show({$modelName} \$item)
    {
        return view('frontend." . strtolower($entityShortName) . ".show', compact('item'));
    }

    public function edit({$modelName} \$item)
    {
        return view('frontend." . strtolower($entityShortName) . ".edit', compact('item'));
    }

    public function update(Request \$request, {$modelName} \$item)
    {
        try {
            \$item->update(\$request->all());
            return redirect()->route('frontend." . strtolower($entityShortName) . ".index')
                ->withFlashSuccess('{$entityDisplayTitle} updated successfully!');
        } catch (\\Exception \$e) {
            return redirect()->back()
                ->withErrors('Error updating {$entityDisplayTitle}: ' . \$e->getMessage());
        }
    }

    public function destroy({$modelName} \$item)
    {
        try {
            \$item->delete();
            return redirect()->route('frontend." . strtolower($entityShortName) . ".index')
                ->withFlashSuccess('{$entityDisplayTitle} deleted successfully!');
        } catch (\\Exception \$e) {
            return redirect()->back()
                ->withErrors('Error deleting {$entityDisplayTitle}: ' . \$e->getMessage());
        }
    }
}
";
        file_put_contents($controllerPath, $controllerContent);

        // Generate Routes file using the frontend route convention.
        $routesContent = "<?php

use App\\Http\\Controllers\\Frontend\\User\\{$modelName}Controller;

Route::prefix('" . strtolower($entityShortName) . "')->name('" . strtolower($entityShortName) . ".')->group(function () {
    Route::get('/', [{$modelName}Controller::class, 'index'])->name('index');
    Route::get('/create', [{$modelName}Controller::class, 'create'])->name('create');
    Route::post('/', [{$modelName}Controller::class, 'store'])->name('store');
    Route::get('/{item}', [{$modelName}Controller::class, 'show'])->name('show');
    Route::get('/{item}/edit', [{$modelName}Controller::class, 'edit'])->name('edit');
    Route::put('/{item}', [{$modelName}Controller::class, 'update'])->name('update');
    Route::delete('/{item}', [{$modelName}Controller::class, 'destroy'])->name('destroy');
});
";
        file_put_contents($routesPath, $routesContent);

        // Prepare entity configuration data to be saved in JSON.
        $configData = [
            'model_name' => $modelName,
            'table_name' => $tableName,
            'entity_short_name' => $entityShortName,
            'entity_display_title' => $entityDisplayTitle,
            'fields' => $fields,
            'created_at' => date('Y-m-d H:i:s')
        ];
        file_put_contents($configPath, json_encode($configData, JSON_PRETTY_PRINT));

        // Generate Blade view files using a Bootstrap card layout.
        // 1. index.blade.php (List view using DataTables)
        $indexView = "<!-- resources/views/frontend/" . strtolower($entityShortName) . "/index.blade.php -->
@extends('frontend.layouts.app')

@push('after-styles')
    <link rel=\"stylesheet\" href=\"https://cdn.datatables.net/2.0.8/css/dataTables.dataTables.css\">
    <link rel=\"stylesheet\" href=\"https://cdn.datatables.net/buttons/3.0.2/css/buttons.dataTables.css\">
    <link rel=\"stylesheet\" href=\"https://cdn.datatables.net/searchbuilder/1.7.1/css/searchBuilder.dataTables.css\">
    <link rel=\"stylesheet\" href=\"https://cdn.datatables.net/datetime/1.5.2/css/dataTables.dateTime.min.css\">
@endpush

@section('title', '{$entityDisplayTitle} List')

@section('content')
<div class=\"container-fluid\">
    <div class=\"row mb-3\">
        <div class=\"col-12\">
            <a href=\"{{ route('frontend." . strtolower($entityShortName) . ".create') }}\" class=\"btn btn-primary\">Add New {$entityDisplayTitle}</a>
        </div>
    </div>
    <div class=\"row\">
        <div class=\"col-12\">
            <div class=\"card\">
                <div class=\"card-header\">
                    <h3 class=\"card-title\">{$entityDisplayTitle} List</h3>
                </div>
                <div class=\"card-body\">
                    <table class=\"table table-bordered\">
                        <thead>
                            <tr>
                                <th>#</th>
                                " . implode("\n                                ", array_map(function ($field) {
                $view = htmlspecialchars($field['view_name'], ENT_QUOTES, 'UTF-8');
                return '<th>' . $view . '</th>';
            }, $fields)) . "
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach(\$items as \$key => \$item)
                                <tr>
                                    <td>{{ \$key + 1 }}</td>
                                    " . implode("\n", array_map(function ($field) {
                $col = htmlspecialchars($field['column_name'], ENT_QUOTES, 'UTF-8');
                return '<td>{{ $item->' . $col . ' }}</td>';
            }, $fields)) . "

            <td>
                                        <a href=\"{{ route('frontend." . strtolower($entityShortName) . ".show', \$item->id) }}\" class=\"btn btn-sm btn-info\">View</a>
                                        <a href=\"{{ route('frontend." . strtolower($entityShortName) . ".edit', \$item->id) }}\" class=\"btn btn-sm btn-primary\">Edit</a>
                                        <form action=\"{{ route('frontend." . strtolower($entityShortName) . ".destroy', \$item->id) }}\" method=\"POST\" class=\"d-inline\" onsubmit=\"return confirm('Are you sure?')\">
                                            @csrf
                                            @method('DELETE')
                                            <button type=\"submit\" class=\"btn btn-sm btn-danger\">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('after-scripts')
    <script src=\"https://cdn.datatables.net/2.0.8/js/dataTables.js\"></script>
    <script src=\"https://cdn.datatables.net/buttons/3.0.2/js/dataTables.buttons.js\"></script>
    <script src=\"https://cdn.datatables.net/buttons/3.0.2/js/buttons.dataTables.js\"></script>
    <script src=\"https://cdn.datatables.net/searchbuilder/1.7.1/js/dataTables.searchBuilder.js\"></script>
    <script src=\"https://cdn.datatables.net/searchbuilder/1.7.1/js/searchBuilder.dataTables.js\"></script>
    <script src=\"https://cdn.datatables.net/datetime/1.5.2/js/dataTables.dateTime.min.js\"></script>
    <script src=\"https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js\"></script>
    <script src=\"https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js\"></script>
    <script src=\"https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js\"></script>
    <script src=\"https://cdn.datatables.net/buttons/3.0.2/js/buttons.html5.min.js\"></script>
    <script src=\"https://cdn.datatables.net/buttons/3.0.2/js/buttons.print.min.js\"></script>
    <script>
        \$(document).ready(function () {
            var table = new DataTable('.table', {
                \"paging\": false,
                scrollY: 465,
                layout: {
                    top: {
                        searchBuilder: { }
                    },
                    topStart: {
                        buttons: ['copy', 'csv', 'excel', 'pdf', 'print']
                    }
                }
            });
        });
    </script>
@endpush
";
        file_put_contents($viewsDir . "index.blade.php", $indexView);

        // 2. create.blade.php (form view with CKEditor if rich_text field exists)
        $createForm = "<!-- resources/views/frontend/" . strtolower($entityShortName) . "/create.blade.php -->
@extends('frontend.layouts.app')

@section('title', 'Add New {$entityDisplayTitle}')

@section('content')
<div class=\"container-fluid\">
    <div class=\"row\">
        <div class=\"col-12\">
            <div class=\"card\">
                <div class=\"card-header\">
                    <h3 class=\"card-title\">Add New {$entityDisplayTitle}</h3>
                </div>
                <div class=\"card-body\">
                    <form action=\"{{ route('frontend." . strtolower($entityShortName) . ".store') }}\" method=\"POST\">
                        @csrf
";
        foreach ($fields as $field) {
            $col = htmlspecialchars($field['column_name'], ENT_QUOTES, 'UTF-8');
            $view = htmlspecialchars($field['view_name'], ENT_QUOTES, 'UTF-8');
            $dataType = $field['data_type'];
            if ($dataType === 'rich_text') {
                // Rich text field with CKEditor
                $createForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <textarea class=\"form-control\" name=\"{$col}\" id=\"{$col}\" rows=\"5\"></textarea>
                            <small class=\"form-text text-muted\">Kindly explain in detail</small>
                        </div>
        ";
            } elseif ($dataType === 'long_text') {
                // Plain textarea without CKEditor
                $createForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <textarea class=\"form-control\" name=\"{$col}\" id=\"{$col}\" rows=\"5\"></textarea>
                        </div>
        ";
            } elseif ($dataType === 'integer') {
                $createForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <input type=\"number\" name=\"{$col}\" id=\"{$col}\" class=\"form-control\" required>
                        </div>
        ";
            } elseif ($dataType === 'money') {
                $createForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <input type=\"number\" step=\"0.01\" name=\"{$col}\" id=\"{$col}\" class=\"form-control\" required>
                        </div>
        ";
            } elseif ($dataType === 'date') {
                $createForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <input type=\"date\" name=\"{$col}\" id=\"{$col}\" class=\"form-control\" required>
                        </div>
        ";
            } elseif ($dataType === 'datetime') {
                $createForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <input type=\"datetime-local\" name=\"{$col}\" id=\"{$col}\" class=\"form-control\" required>
                        </div>
        ";
            } elseif ($dataType === 'boolean') {
                // For checkbox, a hidden field is often added to send 0 when unchecked.
                $createForm .= "
                        <div class=\"form-group form-check\">
                            <input type=\"hidden\" name=\"{$col}\" value=\"0\">
                            <input type=\"checkbox\" class=\"form-check-input\" name=\"{$col}\" id=\"{$col}\" value=\"1\">
                            <label class=\"form-check-label\" for=\"{$col}\">{$view}</label>
                        </div>
        ";
            } elseif ($dataType === 'select') {
                // Dummy options for a select dropdown.
                $createForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <select name=\"{$col}\" id=\"{$col}\" class=\"form-control\" required>
                                <option value=\"\">-- Select --</option>
                                <option value=\"Option1\">Option1</option>
                                <option value=\"Option2\">Option2</option>
                                <option value=\"Option3\">Option3</option>
                            </select>
                        </div>
        ";
            } elseif ($dataType === 'radio') {
                // Dummy radio options.
                $createForm .= "
                        <div class=\"form-group\">
                            <label>{$view}</label><br>
                            <div class=\"form-check form-check-inline\">
                                <input class=\"form-check-input\" type=\"radio\" name=\"{$col}\" id=\"{$col}_1\" value=\"Option1\" required>
                                <label class=\"form-check-label\" for=\"{$col}_1\">Option1</label>
                            </div>
                            <div class=\"form-check form-check-inline\">
                                <input class=\"form-check-input\" type=\"radio\" name=\"{$col}\" id=\"{$col}_2\" value=\"Option2\">
                                <label class=\"form-check-label\" for=\"{$col}_2\">Option2</label>
                            </div>
                            <div class=\"form-check form-check-inline\">
                                <input class=\"form-check-input\" type=\"radio\" name=\"{$col}\" id=\"{$col}_3\" value=\"Option3\">
                                <label class=\"form-check-label\" for=\"{$col}_3\">Option3</label>
                            </div>
                        </div>
        ";
            } elseif ($dataType === 'foreign') {
                // For a foreign key, we use the related table details.
                $relatedModel = !empty($field['related_model']) ? trim($field['related_model']) : 'RelatedModel';
                $displayColumn = !empty($field['display_column']) ? trim($field['display_column']) : 'name';
                $createForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <select name=\"{$col}\" id=\"{$col}\" class=\"form-control\" required>
                                <option value=\"\">-- Select --</option>
                                @foreach(\App\Models\\{$relatedModel}::all() as \$option)
                                    <option value=\"{{ \$option->id }}\">{{ \$option->{$displayColumn} }}</option>
                                @endforeach
                            </select>
                        </div>
        ";
            } else {
                // Default: use text input.
                $createForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <input type=\"text\" name=\"{$col}\" id=\"{$col}\" class=\"form-control\" required>
                        </div>
        ";
            }
        }

        $createForm .= "
                        <button type=\"submit\" class=\"btn btn-primary\">Save</button>
                        <a href=\"{{ route('frontend." . strtolower($entityShortName) . ".index') }}\" class=\"btn btn-secondary\">Cancel</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('after-scripts')
    <!-- Load CKEditor only if needed -->
    <script src=\"https://cdn.ckeditor.com/ckeditor5/36.0.1/classic/ckeditor.js\"></script>
    <script>
        document.querySelectorAll('textarea').forEach(function(textarea) {
            if(textarea.id) {
                ClassicEditor.create(textarea).catch(error => { console.error(error); });
            }
        });
    </script>
@endpush
";

        file_put_contents($viewsDir . "create.blade.php", $createForm);

        // 3. edit.blade.php – similar to create but with pre-populated values.
        $editForm = "<!-- resources/views/frontend/" . strtolower($entityShortName) . "/edit.blade.php -->
@extends('frontend.layouts.app')

@section('title', 'Edit {$entityDisplayTitle}')

@section('content')
<div class=\"container-fluid\">
    <div class=\"row\">
        <div class=\"col-12\">
            <div class=\"card\">
                <div class=\"card-header\">
                    <h3 class=\"card-title\">Edit {$entityDisplayTitle}</h3>
                </div>
                <div class=\"card-body\">
                    <form action=\"{{ route('frontend." . strtolower($entityShortName) . ".update', \$item->id) }}\" method=\"POST\" onsubmit=\"return confirm('Are you sure you want to update this?')\">
                        @csrf
                        @method('PUT')
";
        foreach ($fields as $field) {
            $col = htmlspecialchars($field['column_name'], ENT_QUOTES, 'UTF-8');
            $view = htmlspecialchars($field['view_name'], ENT_QUOTES, 'UTF-8');
            $dataType = $field['data_type'];
            if ($dataType === 'rich_text') {
                $editForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <textarea class=\"form-control\" name=\"{$col}\" id=\"{$col}\" rows=\"5\">{{ \$item->{$col} }}</textarea>
                            <small class=\"form-text text-muted\">Kindly explain in detail</small>
                        </div>
        ";
            } elseif ($dataType === 'long_text') {
                $editForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <textarea class=\"form-control\" name=\"{$col}\" id=\"{$col}\" rows=\"5\">{{ \$item->{$col} }}</textarea>
                        </div>
        ";
            } elseif ($dataType === 'integer') {
                $editForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <input type=\"number\" name=\"{$col}\" id=\"{$col}\" class=\"form-control\" value=\"{{ \$item->{$col} }}\" required>
                        </div>
        ";
            } elseif ($dataType === 'money') {
                $editForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <input type=\"number\" step=\"0.01\" name=\"{$col}\" id=\"{$col}\" class=\"form-control\" value=\"{{ \$item->{$col} }}\" required>
                        </div>
        ";
            } elseif ($dataType === 'date') {
                $editForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <input type=\"date\" name=\"{$col}\" id=\"{$col}\" class=\"form-control\" value=\"{{ \$item->{$col} }}\" required>
                        </div>
        ";
            } elseif ($dataType === 'datetime') {
                $editForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <input type=\"datetime-local\" name=\"{$col}\" id=\"{$col}\" class=\"form-control\" value=\"{{ \$item->{$col} }}\" required>
                        </div>
        ";
            } elseif ($dataType === 'boolean') {
                $editForm .= "
                        <div class=\"form-group form-check\">
                            <input type=\"hidden\" name=\"{$col}\" value=\"0\">
                            <input type=\"checkbox\" class=\"form-check-input\" name=\"{$col}\" id=\"{$col}\" value=\"1\" {{ \$item->{$col} ? 'checked' : '' }}>
                            <label class=\"form-check-label\" for=\"{$col}\">{$view}</label>
                        </div>
        ";
            } elseif ($dataType === 'select') {
                $editForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <select name=\"{$col}\" id=\"{$col}\" class=\"form-control\" required>
                                <option value=\"\">-- Select --</option>
                                <option value=\"Option1\" {{ \$item->{$col} == 'Option1' ? 'selected' : '' }}>Option1</option>
                                <option value=\"Option2\" {{ \$item->{$col} == 'Option2' ? 'selected' : '' }}>Option2</option>
                                <option value=\"Option3\" {{ \$item->{$col} == 'Option3' ? 'selected' : '' }}>Option3</option>
                            </select>
                        </div>
        ";
            } elseif ($dataType === 'radio') {
                $editForm .= "
                        <div class=\"form-group\">
                            <label>{$view}</label><br>
                            <div class=\"form-check form-check-inline\">
                                <input class=\"form-check-input\" type=\"radio\" name=\"{$col}\" id=\"{$col}_1\" value=\"Option1\" {{ \$item->{$col} == 'Option1' ? 'checked' : '' }} required>
                                <label class=\"form-check-label\" for=\"{$col}_1\">Option1</label>
                            </div>
                            <div class=\"form-check form-check-inline\">
                                <input class=\"form-check-input\" type=\"radio\" name=\"{$col}\" id=\"{$col}_2\" value=\"Option2\" {{ \$item->{$col} == 'Option2' ? 'checked' : '' }}>
                                <label class=\"form-check-label\" for=\"{$col}_2\">Option2</label>
                            </div>
                            <div class=\"form-check form-check-inline\">
                                <input class=\"form-check-input\" type=\"radio\" name=\"{$col}\" id=\"{$col}_3\" value=\"Option3\" {{ \$item->{$col} == 'Option3' ? 'checked' : '' }}>
                                <label class=\"form-check-label\" for=\"{$col}_3\">Option3</label>
                            </div>
                        </div>
        ";
            } elseif ($dataType === 'foreign') {
                $relatedModel = !empty($field['related_model']) ? trim($field['related_model']) : 'RelatedModel';
                $displayColumn = !empty($field['display_column']) ? trim($field['display_column']) : 'name';
                $editForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <select name=\"{$col}\" id=\"{$col}\" class=\"form-control\" required>
                                <option value=\"\">-- Select --</option>
                                @foreach(\App\Models\\{$relatedModel}::all() as \$option)
                                    <option value=\"{{ \$option->id }}\" {{ \$item->{$col} == \$option->id ? 'selected' : '' }}>{{ \$option->{$displayColumn} }}</option>
                                @endforeach
                            </select>
                        </div>
        ";
            } else {
                $editForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <input type=\"text\" name=\"{$col}\" id=\"{$col}\" class=\"form-control\" value=\"{{ \$item->{$col} }}\" required>
                        </div>
        ";
            }
        }
        $editForm .= "
                        <button type=\"submit\" class=\"btn btn-primary\">Update</button>
                        <a href=\"{{ route('frontend." . strtolower($entityShortName) . ".index') }}\" class=\"btn btn-secondary\">Cancel</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('after-scripts')
    <script src=\"https://cdn.ckeditor.com/ckeditor5/36.0.1/classic/ckeditor.js\"></script>
    <script>
        document.querySelectorAll('textarea').forEach(function(textarea) {
            ClassicEditor.create(textarea).catch(error => { console.error(error); });
        });
    </script>
@endpush
";

        file_put_contents($viewsDir . "edit.blade.php", $editForm);

        // 4. show.blade.php – display details in a Bootstrap card.
        $showView = "<!-- resources/views/frontend/" . strtolower($entityShortName) . "/show.blade.php -->
@extends('frontend.layouts.app')

@section('title', '{$entityDisplayTitle} Details')

@section('content')
<div class=\"container-fluid\">
    <div class=\"row\">
        <div class=\"col-12\">
            <div class=\"card\">
                <div class=\"card-header\">
                    <h3 class=\"card-title\">{$entityDisplayTitle} Details</h3>
                </div>
                <div class=\"card-body\">
                    ";
        foreach ($fields as $field) {
            $col = htmlspecialchars($field['column_name'], ENT_QUOTES, 'UTF-8');
            $view = htmlspecialchars($field['view_name'], ENT_QUOTES, 'UTF-8');
            $dataType = $field['data_type'];

            if ($dataType === 'rich_text') {
                // Render rich text as HTML.
                $showView .= "<p><strong>{$view}:</strong> {!! \$item->{$col} !!}</p>\n";
            } elseif ($dataType === 'integer' || $dataType === 'money') {
                // Use the custom function checkIntNumber().
                $showView .= "<p><strong>{$view}:</strong> {{ checkIntNumber(\$item->{$col}) }}</p>\n";
            } elseif ($dataType === 'foreign') {
                // Determine the display column (default to 'name').
                $displayColumn = 'name';
                if (!empty($field['display_column'])) {
                    $displayColumn = htmlspecialchars(trim($field['display_column']), ENT_QUOTES, 'UTF-8');
                }
                // Use the relationship method; note that we assume the relationship method
                // is named as the column name plus "Relation".
                $showView .= "<p><strong>{$view}:</strong> {{ \$item->{$col}Relation ? \$item->{$col}Relation->{$displayColumn} : '' }}</p>\n";
            } else {
                // For all other types, display the value normally.
                $showView .= "<p><strong>{$view}:</strong> {{ \$item->{$col} }}</p>\n";
            }
        }
        $showView .= "
                    <a href=\"{{ route('frontend." . strtolower($entityShortName) . ".index') }}\" class=\"btn btn-secondary\">Back</a>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
";
        file_put_contents($viewsDir . "show.blade.php", $showView);
    }

    /**
     * Generate a one‑page CRUD (all in index.blade.php with modals) for a given entity.
     *
     * @param string $modelName         Eloquent model class name, e.g. "Client"
     * @param string $tableName         Database table name, e.g. "clients"
     * @param string $entityShortName   URL / variable name, e.g. "clients"
     * @param array  $fields            Array of field definitions:
     *                                  [
     *                                    ['column_name'=>'name','view_name'=>'Name','data_type'=>'short_text',…],
     *                                    …
     *                                  ]
     * @param string $projectDir        Filesystem path to project root, ending with slash
     */

$home_link = '<a href="index.php">Home</a>';
    // Final message
    echo "CRUD Boilerplate for {$modelName} has been generated successfully in: {$projectDir}. {$home_link}";
} else {
        header('Location: index.php');
        exit;
    }


/**
 * Generate a one‑page CRUD interface (all in index.blade.php with modals) for a given entity.
 *
 * @param string $modelName             Eloquent model class name, e.g. "Client"
 * @param string $tableName             Database table name, e.g. "clients"
 * @param string $entityShortName       URL / variable name, e.g. "clients"
 * @param string $entityDisplayTitle    Display Title, e.g. "External Lawyer"
 * @param array  $fields                Array of field definitions:
 *                                      [
 *                                          ['column_name'=>'name','view_name'=>'Name','data_type'=>'short_text',
 *                                              'related_table'=>..., 'related_model'=>..., 'display_column'=>...],
 *                                          …
 *                                      ]
 * @param string $projectDir        Filesystem path to project root, ending with slash
 */
function generateOnePageCRUD($modelName, $tableName, $entityShortName, $entityDisplayTitle, $fields, $projectDir)
{
    // 1) Ensure directories exist
    $dirs = [
        "app/Http/Controllers/Frontend/User/",
        "resources/views/frontend/{$entityShortName}/",
        "routes/",
        "database/migrations/"
    ];
    foreach ($dirs as $sub) {
        $dir = $projectDir . $sub;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }

    // 2) Save JSON config
    $config = [
        'model_name'        => $modelName,
        'table_name'        => $tableName,
        'entity_short_name' => $entityShortName,
        'entity_display_title' => $entityDisplayTitle,
        'fields'            => $fields,
        'created_at'        => date('Y-m-d H:i:s'),
    ];
    file_put_contents($projectDir . 'entity_config.json', json_encode($config, JSON_PRETTY_PRINT));

    // 3) Generate Migration
    $migrationClass = "Create" . ucfirst($modelName) . "Table";
    $timestamp      = date('Y_m_d_His');
    $migrationPath  = "{$projectDir}database/migrations/{$timestamp}_create_{$tableName}_table.php";

    $migration = "<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

class {$migrationClass} extends Migration
{
    public function up()
    {
        Schema::create('{$tableName}', function (Blueprint \$table) {
            \$table->bigIncrements('id');
";

    foreach ($fields as $f) {
        $col = $f['column_name'];
        switch ($f['data_type']) {
            case 'short_text':
                $migration .= "            \$table->string('{$col}', 191);\n";
                break;
            case 'long_text':
            case 'rich_text':
                $migration .= "            \$table->text('{$col}');\n";
                break;
            case 'integer':
                $migration .= "            \$table->bigInteger('{$col}');\n";
                break;
            case 'money':
                $migration .= "            \$table->string('{$col}', 20);\n";
                break;
            case 'date':
                $migration .= "            \$table->date('{$col}');\n";
                break;
            case 'datetime':
                $migration .= "            \$table->dateTime('{$col}');\n";
                break;
            case 'boolean':
                $migration .= "            \$table->tinyInteger('{$col}');\n";
                break;
            case 'select':
            case 'radio':
                $migration .= "            \$table->string('{$col}', 191);\n";
                break;
            case 'foreign':
                $migration .= "            \$table->bigInteger('{$col}');\n";
                if (!empty($f['related_table'])) {
                    $rt = $f['related_table'];
                    $migration .= "            \$table->foreign('{$col}')->references('id')->on('{$rt}')->onDelete('cascade');\n";
                }
                break;
            default:
                $migration .= "            \$table->string('{$col}', 191);\n";
                break;
        }
    }

    $migration .= "            \$table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('{$tableName}');
    }
}
";

    file_put_contents($migrationPath, $migration);

    // 4) Generate AJAX-style Controller
    $controllerName = "{$modelName}AjaxController";
    $controllerPath = "{$projectDir}app/Http/Controllers/Frontend/User/{$controllerName}.php";

    $controller = "<?php

namespace App\\Http\\Controllers\\Frontend\\User;

use App\\Http\\Controllers\\Controller;
use App\\Models\\{$modelName};
use Illuminate\\Http\\Request;

class {$controllerName} extends Controller
{
    public function index()
    {
        \${$entityShortName} = {$modelName}::with([
";

    // eager-load foreign relations
    foreach ($fields as $f) {
        if ($f['data_type'] === 'foreign') {
            $method = lcfirst($f['column_name']) . "Relation";
            $controller .= "            '{$method}',\n";
        }
    }

    $controller .= "        ])->get();

        return view('frontend.{$entityShortName}.index', compact('{$entityShortName}'));
    }

    public function store(Request \$request)
    {
        {$modelName}::create(\$request->all());
        return back()->withFlashSuccess('{$entityDisplayTitle} created successfully.');
    }

    public function update(Request \$request, \$id)
    {
        \${$entityShortName} = {$modelName}::findOrFail(\$id);
        \${$entityShortName}->update(\$request->all());
        return back()->withFlashSuccess('{$entityDisplayTitle} updated successfully.');
    }

    public function destroy(\$id)
    {
        {$modelName}::destroy(\$id);
        return back()->withFlashSuccess('{$entityDisplayTitle} deleted successfully.');
    }
}
";

    file_put_contents($controllerPath, $controller);

    // 5) Generate Routes
    $routesPath = "{$projectDir}routes/{$entityShortName}.php";

    $routes = "<?php

use App\\Http\\Controllers\\Frontend\\User\\{$controllerName};

Route::prefix('{$entityShortName}')->name('{$entityShortName}.')->group(function () {
    Route::get('/',       [{$controllerName}::class, 'index'])->name('index');
    Route::post('/',      [{$controllerName}::class, 'store'])->name('store');
    Route::put('/{id}',   [{$controllerName}::class, 'update'])->name('update');
    Route::delete('/{id}',[{$controllerName}::class, 'destroy'])->name('destroy');
});
";

    file_put_contents($routesPath, $routes);

    // 6) Generate One‑Page Blade View (index.blade.php)
    $viewPath = "{$projectDir}resources/views/frontend/{$entityShortName}/index.blade.php";

    $blade  = "@extends('frontend.layouts.app')\n\n";
    $blade .= "@push('after-styles')\n";
    $blade .= "  <link rel=\"stylesheet\" href=\"https://cdn.datatables.net/2.0.8/css/dataTables.dataTables.css\">\n";
    $blade .= "  <link rel=\"stylesheet\" href=\"https://cdn.datatables.net/buttons/3.0.2/css/buttons.dataTables.css\">\n";
    $blade .= "@endpush\n\n";
    $blade .= "@section('title', 'Manage {$entityDisplayTitle}')\n\n";
    $blade .= "@section('content')\n";
    $blade .= "<div class=\"container\">\n";
    $blade .= "  <div class=\"row\">\n    <div class=\"col-12\">\n";
    $blade .= "      <div class=\"card\">\n";
    $blade .= "        <div class=\"card-header d-flex justify-content-between align-items-center\">\n";
    $blade .= "          <h3 class=\"card-title\">{$entityDisplayTitle} List</h3>\n";
    $blade .= "          <button class=\"btn btn-sm btn-primary\" data-toggle=\"modal\" data-target=\"#modalCreate-{$entityShortName}\">Add New {$entityDisplayTitle}</button>\n";
    $blade .= "        </div>\n";
    $blade .= "        <div class=\"card-body\">\n";
    $blade .= "          <table id=\"tbl\" class=\"table table-bordered\">\n";
    $blade .= "            <thead>\n";
    $blade .= "              <tr>\n                <th>#</th>\n";
    foreach ($fields as $f) {
        $blade .= "                <th>{$f['view_name']}</th>\n";
    }
    $blade .= "                <th>Actions</th>\n";
    $blade .= "              </tr>\n            </thead>\n";
    $blade .= "            <tbody>\n";
    $blade .= "              @foreach(\${$entityShortName} as \$key => \${$entityShortName}Item)\n";
    $blade .= "                <tr>\n";
    $blade .= "                  <td>{{ \$key + 1 }}</td>\n";
    foreach ($fields as $f) {
        $col = $f['column_name'];
        switch ($f['data_type']) {
            case 'rich_text':
                $blade .= "                  <td>{!! \${$entityShortName}Item->{$col} !!}</td>\n";
                break;
            case 'integer':
            case 'money':
                $blade .= "                  <td>{{ checkIntNumber(\${$entityShortName}Item->{$col}) }}</td>\n";
                break;
            case 'foreign':
                $method = lcfirst($col) . "Relation";
                $disp   = $f['display_column'] ?? 'name';
                $blade .= "                  <td>{{ \${$entityShortName}Item->{$method} ? \${$entityShortName}Item->{$method}->{$disp} : '' }}</td>\n";
                break;
            default:
                $blade .= "                  <td>{{ \${$entityShortName}Item->{$col} }}</td>\n";
                break;
        }
    }
    $blade .= "                  <td>\n";
    $blade .= "                    <button class=\"btn btn-sm btn-info\" data-toggle=\"modal\" data-target=\"#modalEdit-{$entityShortName}-{{ \${$entityShortName}Item->id }}\">Edit</button>\n";
    $blade .= "                    <form action=\"{{ route('{$entityShortName}.destroy', \${$entityShortName}Item->id) }}\" method=\"POST\" class=\"d-inline\" onsubmit=\"return confirm('Are you sure?')\">\n";
    $blade .= "                      @csrf @method('DELETE')\n";
    $blade .= "                      <button class=\"btn btn-sm btn-danger\">Delete</button>\n";
    $blade .= "                    </form>\n";
    $blade .= "                  </td>\n";
    $blade .= "                </tr>\n";
    // Edit Modal
    $blade .= "                <div class=\"modal fade\" id=\"modalEdit-{$entityShortName}-{{ \${$entityShortName}Item->id }}\" tabindex=\"-1\">\n";
    $blade .= "                  <div class=\"modal-dialog modal-lg\"><div class=\"modal-content\">\n";
    $blade .= "                    <form action=\"{{ route('{$entityShortName}.update', \${$entityShortName}Item->id) }}\" method=\"POST\">\n";
    $blade .= "                      @csrf @method('PUT')\n";
    $blade .= "                      <div class=\"modal-header\">\n";
    $blade .= "                        <h5 class=\"modal-title\">Edit {$entityDisplayTitle}</h5>\n";
    $blade .= "                        <button type=\"button\" class=\"close\" data-dismiss=\"modal\">&times;</button>\n";
    $blade .= "                      </div>\n";
    $blade .= "                      <div class=\"modal-body\">\n";

    foreach ($fields as $f) {
        $col = $f['column_name'];
        $viewLabel = $f['view_name'];
        switch ($f['data_type']) {
            case 'rich_text':
                $blade .= "                        <div class=\"form-group\"><label>{$viewLabel}</label>\n";
                $blade .= "                          <textarea class=\"form-control\" name=\"{$col}\" rows=\"4\">{{ \${$entityShortName}Item->{$col} }}</textarea></div>\n";
                break;
            case 'long_text':
                $blade .= "                        <div class=\"form-group\"><label>{$viewLabel}</label>\n";
                $blade .= "                          <textarea class=\"form-control\" name=\"{$col}\" rows=\"4\">{{ \${$entityShortName}Item->{$col} }}</textarea></div>\n";
                break;
            case 'integer':
                $blade .= "                        <div class=\"form-group\"><label>{$viewLabel}</label>\n";
                $blade .= "                          <input type=\"number\" class=\"form-control\" name=\"{$col}\" value=\"{{ \${$entityShortName}Item->{$col} }}\"></div>\n";
                break;
            case 'money':
                $blade .= "                        <div class=\"form-group\"><label>{$viewLabel}</label>\n";
                $blade .= "                          <input type=\"number\" step=\"0.01\" class=\"form-control\" name=\"{$col}\" value=\"{{ \${$entityShortName}Item->{$col} }}\"></div>\n";
                break;
            case 'date':
                $blade .= "                        <div class=\"form-group\"><label>{$viewLabel}</label>\n";
                $blade .= "                          <input type=\"date\" class=\"form-control\" name=\"{$col}\" value=\"{{ \${$entityShortName}Item->{$col} }}\"></div>\n";
                break;
            case 'datetime':
                $blade .= "                        <div class=\"form-group\"><label>{$viewLabel}</label>\n";
                $blade .= "                          <input type=\"datetime-local\" class=\"form-control\" name=\"{$col}\" value=\"{{ \${$entityShortName}Item->{$col} }}\"></div>\n";
                break;
            case 'boolean':
                $blade .= "                        <div class=\"form-check\">\n";
                $blade .= "                          <input type=\"hidden\" name=\"{$col}\" value=\"0\">\n";
                $blade .= "                          <input type=\"checkbox\" class=\"form-check-input\" name=\"{$col}\" value=\"1\" {{ \${$entityShortName}Item->{$col}?'checked':'' }}>\n";
                $blade .= "                          <label class=\"form-check-label\">{$viewLabel}</label>\n";
                $blade .= "                        </div>\n";
                break;
            case 'select':
                $blade .= "                        <div class=\"form-group\"><label>{$viewLabel}</label>\n";
                $blade .= "                          <select class=\"form-control\" name=\"{$col}\">\n";
                $blade .= "                            <option value=\"\">-- Select --</option>\n";
                $blade .= "                            <option value=\"Option1\" {{ \${$entityShortName}Item->{$col}=='Option1'?'selected':'' }}>Option1</option>\n";
                $blade .= "                          </select></div>\n";
                break;
            case 'radio':
                $blade .= "                        <div class=\"form-group\"><label>{$viewLabel}</label><br>\n";
                for ($i = 1; $i <= 3; $i++) {
                    $opt = "Option{$i}";
                    $sel = "{{ \${$entityShortName}Item->{$col}=='{$opt}'?'checked':'' }}";
                    $blade .= "                          <div class=\"form-check form-check-inline\">\n";
                    $blade .= "                            <input class=\"form-check-input\" type=\"radio\" name=\"{$col}\" value=\"{$opt}\" {$sel}>\n";
                    $blade .= "                            <label class=\"form-check-label\">{$opt}</label>\n";
                    $blade .= "                          </div>\n";
                }
                $blade .= "                        </div>\n";
                break;
            case 'foreign':
                $rm = $f['related_model'];
                $dc = $f['display_column'];
                $blade .= "                        <div class=\"form-group\"><label>{$viewLabel}</label>\n";
                $blade .= "                          <select class=\"form-control\" name=\"{$col}\">\n";
                $blade .= "                            <option value=\"\">-- Select --</option>\n";
                $blade .= "                            @foreach({$rm}::all() as \$opt)\n";
                $blade .= "                              <option value=\"{{ \$opt->id }}\" {{ \$opt->id==\${$entityShortName}Item->{$col}?'selected':'' }}>{{ \$opt->{$dc} }}</option>\n";
                $blade .= "                            @endforeach\n";
                $blade .= "                          </select></div>\n";
                break;
            default:
                $blade .= "                        <div class=\"form-group\"><label>{$viewLabel}</label>\n";
                $blade .= "                          <input type=\"text\" class=\"form-control\" name=\"{$col}\" value=\"{{ \${$entityShortName}Item->{$col} }}\"></div>\n";
                break;
        }
    }
    $blade .= "                      </div>\n";
    $blade .= "                      <div class=\"modal-footer\">\n";
    $blade .= "                        <button type=\"button\" class=\"btn btn-secondary\" data-dismiss=\"modal\">Cancel</button>\n";
    $blade .= "                        <button type=\"submit\" class=\"btn btn-primary\">Save Changes</button>\n";
    $blade .= "                      </div>\n";
    $blade .= "                    </form>\n";
    $blade .= "                  </div></div>\n";
    $blade .= "                </div>\n";
    $blade .= "              @endforeach\n";
    $blade .= "            </tbody>\n";
    $blade .= "          </table>\n";
    $blade .= "        </div>\n";
    $blade .= "      </div>\n";
    $blade .= "    </div>\n";
    $blade .= "  </div>\n";

    // Create Modal
    $blade .= "  <div class=\"modal fade\" id=\"modalCreate-{$entityShortName}\" tabindex=\"-1\">\n";
    $blade .= "    <div class=\"modal-dialog modal-lg\"><div class=\"modal-content\">\n";
    $blade .= "      <form action=\"{{ route('{$entityShortName}.store') }}\" method=\"POST\">\n";
    $blade .= "        @csrf\n";
    $blade .= "        <div class=\"modal-header\">\n";
    $blade .= "          <h5 class=\"modal-title\">Add New {$entityDisplayTitle}</h5>\n";
    $blade .= "          <button type=\"button\" class=\"close\" data-dismiss=\"modal\">&times;</button>\n";
    $blade .= "        </div>\n";
    $blade .= "        <div class=\"modal-body\">\n";
    foreach ($fields as $f) {
        $col = $f['column_name'];
        $viewLabel = $f['view_name'];
        switch ($f['data_type']) {
            case 'rich_text':
                $blade .= "          <div class=\"form-group\"><label>{$viewLabel}</label>\n";
                $blade .= "            <textarea class=\"form-control\" name=\"{$col}\" rows=\"4\"></textarea></div>\n";
                break;
            case 'long_text':
                $blade .= "          <div class=\"form-group\"><label>{$viewLabel}</label>\n";
                $blade .= "            <textarea class=\"form-control\" name=\"{$col}\" rows=\"4\"></textarea></div>\n";
                break;
            case 'integer':
                $blade .= "          <div class=\"form-group\"><label>{$viewLabel}</label>\n";
                $blade .= "            <input type=\"number\" class=\"form-control\" name=\"{$col}\"></div>\n";
                break;
            case 'money':
                $blade .= "          <div class=\"form-group\"><label>{$viewLabel}</label>\n";
                $blade .= "            <input type=\"number\" step=\"0.01\" class=\"form-control\" name=\"{$col}\"></div>\n";
                break;
            case 'date':
                $blade .= "          <div class=\"form-group\"><label>{$viewLabel}</label>\n";
                $blade .= "            <input type=\"date\" class=\"form-control\" name=\"{$col}\"></div>\n";
                break;
            case 'datetime':
                $blade .= "          <div class=\"form-group\"><label>{$viewLabel}</label>\n";
                $blade .= "            <input type=\"datetime-local\" class=\"form-control\" name=\"{$col}\"></div>\n";
                break;
            case 'boolean':
                $blade .= "          <div class=\"form-check\">\n";
                $blade .= "            <input type=\"hidden\" name=\"{$col}\" value=\"0\">\n";
                $blade .= "            <input type=\"checkbox\" class=\"form-check-input\" name=\"{$col}\" value=\"1\">\n";
                $blade .= "            <label class=\"form-check-label\">{$viewLabel}</label>\n";
                $blade .= "          </div>\n";
                break;
            case 'select':
                $blade .= "          <div class=\"form-group\"><label>{$viewLabel}</label>\n";
                $blade .= "            <select class=\"form-control\" name=\"{$col}\">\n";
                $blade .= "              <option value=\"\">-- Select --</option>\n";
                $blade .= "              <option>Option1</option>\n";
                $blade .= "              <option>Option2</option>\n";
                $blade .= "            </select></div>\n";
                break;
            case 'radio':
                $blade .= "          <div class=\"form-group\"><label>{$viewLabel}</label><br>\n";
                for ($i = 1; $i <= 3; $i++) {
                    $opt = "Option{$i}";
                    $blade .= "            <div class=\"form-check form-check-inline\">\n";
                    $blade .= "              <input class=\"form-check-input\" type=\"radio\" name=\"{$col}\" value=\"{$opt}\">\n";
                    $blade .= "              <label class=\"form-check-label\">{$opt}</label>\n";
                    $blade .= "            </div>\n";
                }
                $blade .= "          </div>\n";
                break;
            case 'foreign':
                $rm = $f['related_model'];
                $dc = $f['display_column'];
                $blade .= "          <div class=\"form-group\"><label>{$viewLabel}</label>\n";
                $blade .= "            <select class=\"form-control\" name=\"{$col}\">\n";
                $blade .= "              <option value=\"\">-- Select --</option>\n";
                $blade .= "              @foreach({$rm}::all() as \$opt)\n";
                $blade .= "                <option value=\"{{ \$opt->id }}\">{{ \$opt->{$dc} }}</option>\n";
                $blade .= "              @endforeach\n";
                $blade .= "            </select></div>\n";
                break;
            default:
                $blade .= "          <div class=\"form-group\"><label>{$viewLabel}</label>\n";
                $blade .= "            <input type=\"text\" class=\"form-control\" name=\"{$col}\"></div>\n";
                break;
        }
    }
    $blade .= "        </div>\n";
    $blade .= "        <div class=\"modal-footer\">\n";
    $blade .= "          <button type=\"button\" class=\"btn btn-secondary\" data-dismiss=\"modal\">Cancel</button>\n";
    $blade .= "          <button type=\"submit\" class=\"btn btn-primary\">Create</button>\n";
    $blade .= "        </div>\n";
    $blade .= "      </form>\n";
    $blade .= "    </div></div>\n";
    $blade .= "  </div>\n";

    $blade .= "</div>\n@endsection\n\n";
    $blade .= "@push('after-scripts')\n";
    $blade .= "  <script src=\"https://cdn.datatables.net/2.0.8/js/dataTables.js\"></script>\n";
    $blade .= "  <script src=\"https://cdn.datatables.net/buttons/3.0.2/js/dataTables.buttons.js\"></script>\n";
    $blade .= "  <script>\n";
    $blade .= "    \$(document).ready(function() {\n";
    $blade .= "      \$('#tbl').DataTable({ dom: 'Bfrtip', buttons: ['copy','csv','excel','pdf','print'] });\n";
    $blade .= "    });\n";
    $blade .= "  </script>\n";
    $blade .= "@endpush\n";

    file_put_contents($viewPath, $blade);

    // Define paths for generated files
    $modelPath = $projectDir . "app/Models/{$modelName}.php";

    // Create directories
    @mkdir(dirname($modelPath), 0777, true);

    // Build fillable and relationship parts
    $fillable = [];
    $relationshipMethods = "";
    foreach ($fields as $field) {
        $col = addslashes(trim($field['column_name']));
        $fillable[] = "'{$col}'";
        if ($field['data_type'] === 'foreign') {
            $relatedModel = !empty($field['related_model']) ? trim($field['related_model']) : 'RelatedModel';
            $methodName = lcfirst($col) . "Relation";
            $relationshipMethods .= "\n    public function {$methodName}()\n    {\n";
            $relationshipMethods .= "        return \$this->belongsTo({$relatedModel}::class, '{$col}');\n";
            $relationshipMethods .= "    }\n";
        }
    }
    $fillableStr = implode(",\n        ", $fillable);

    // Generate Model
    $modelContent = "<?php

namespace App\\Models;

use Illuminate\\Database\\Eloquent\\Model;

class {$modelName} extends Model
{
    protected \$fillable = [
        {$fillableStr}
    ];
    {$relationshipMethods}
}
";
    file_put_contents($modelPath, $modelContent);
}


function generateOnePageCRUD0($modelName, $tableName, $entityShortName, $fields, $projectDir)
{
    // Define paths for generated files
    $controllerPath = $projectDir . "app/Http/Controllers/Frontend/User/{$modelName}Controller.php";
    $modelPath = $projectDir . "app/Models/{$modelName}.php";
    $migrationPath = $projectDir . "database/migrations/" . date('Y_m_d_His') . "_create_{$tableName}_table.php";
    $viewsDir = $projectDir . "resources/views/frontend/" . strtolower($entityShortName) . "/";
    $routesPath = $projectDir . "routes/" . strtolower($entityShortName) . ".php";
    $configPath = $projectDir . "entity_config.json"; // will store entity details in JSON

    // Create directories
    @mkdir(dirname($controllerPath), 0777, true);
    @mkdir(dirname($modelPath), 0777, true);
    @mkdir(dirname($migrationPath), 0777, true);
    @mkdir($viewsDir, 0777, true);
    @mkdir(dirname($routesPath), 0777, true);

    // Build fillable and relationship parts
    $fillable = [];
    $relationshipMethods = "";
    foreach ($fields as $field) {
        $col = addslashes(trim($field['column_name']));
        $fillable[] = "'{$col}'";
        if ($field['data_type'] === 'foreign') {
            $relatedModel = !empty($field['related_model']) ? trim($field['related_model']) : 'RelatedModel';
            $methodName = lcfirst($col) . "Relation";
            $relationshipMethods .= "\n    public function {$methodName}()\n    {\n";
            $relationshipMethods .= "        return \$this->belongsTo({$relatedModel}::class, '{$col}');\n";
            $relationshipMethods .= "    }\n";
        }
    }
    $fillableStr = implode(",\n        ", $fillable);

    // Generate Model
    $modelContent = "<?php

namespace App\\Models;

use Illuminate\\Database\\Eloquent\\Model;

class {$modelName} extends Model
{
    protected \$fillable = [
        {$fillableStr}
    ];
    {$relationshipMethods}
}
";
    file_put_contents($modelPath, $modelContent);

    // Generate Migration file
    $migrationContent = "<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

class Create" . ucfirst($modelName) . "Table extends Migration
{
    public function up()
    {
        Schema::create('{$tableName}', function (Blueprint \$table) {
            \$table->bigIncrements('id');
";
    foreach ($fields as $field) {
        $col = trim($field['column_name']);
        $dataType = $field['data_type'];
        switch ($dataType) {
            case 'short_text':
                $migrationContent .= "            \$table->string('{$col}', 191);\n";
                break;
            case 'long_text':
            case 'rich_text':
                $migrationContent .= "            \$table->text('{$col}');\n";
                break;
            case 'integer':
                $migrationContent .= "            \$table->bigInteger('{$col}');\n";
                break;
            case 'money':
                $migrationContent .= "            \$table->string('{$col}', 20);\n";
                break;
            case 'date':
                $migrationContent .= "            \$table->date('{$col}');\n";
                break;
            case 'datetime':
                $migrationContent .= "            \$table->dateTime('{$col}');\n";
                break;
            case 'boolean':
                $migrationContent .= "            \$table->tinyInteger('{$col}');\n";
                break;
            case 'select':
            case 'radio':
                $migrationContent .= "            \$table->string('{$col}', 191);\n";
                break;
            case 'foreign':
                $migrationContent .= "            \$table->bigInteger('{$col}');\n";
                if (!empty($field['related_table'])) {
                    $relatedTable = trim($field['related_table']);
                    $migrationContent .= "            \$table->foreign('{$col}')->references('id')->on('{$relatedTable}')->onDelete('cascade');\n";
                }
                break;
            default:
                $migrationContent .= "            \$table->string('{$col}', 191);\n";
        }
    }
    $migrationContent .= "            \$table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('{$tableName}');
    }
}
";
    file_put_contents($migrationPath, $migrationContent);

    // Generate Controller file with flash messages (using withFlashSuccess and withErrors)
    $controllerContent = "<?php

namespace App\\Http\\Controllers\\Frontend\\User;

use App\\Http\\Controllers\\Controller;
use App\\Models\\{$modelName};
use Illuminate\\Http\\Request;

class {$modelName}Controller extends Controller
{
    public function index()
    {
        \$".strtolower($entityShortName)." = {$modelName}::all();
        return view('frontend." . strtolower($entityShortName) . ".index', compact('".strtolower($entityShortName)."'));
    }

    public function store(Request \$request)
    {
        try {
            {$modelName}::create(\$request->all());
            return redirect()->back()
                ->withFlashSuccess('{$modelName} created successfully!');
        } catch (\\Exception \$e) {
            return redirect()->back()
                ->withErrors('Error creating {$modelName}: ' . \$e->getMessage());
        }
    }

    public function update(Request \$request, {$modelName} \$item)
    {
        try {
            \$item->update(\$request->all());
            return redirect()->back()
                ->withFlashSuccess('{$modelName} updated successfully!');
        } catch (\\Exception \$e) {
            return redirect()->back()
                ->withErrors('Error updating {$modelName}: ' . \$e->getMessage());
        }
    }

    public function destroy({$modelName} \$item)
    {
        try {
            \$item->delete();
            return redirect()->back()
                ->withFlashSuccess('{$modelName} deleted successfully!');
        } catch (\\Exception \$e) {
            return redirect()->back()
                ->withErrors('Error deleting {$modelName}: ' . \$e->getMessage());
        }
    }
}
";
    file_put_contents($controllerPath, $controllerContent);

    // Generate Routes file using the frontend route convention.
    $routesContent = "<?php

use App\\Http\\Controllers\\Frontend\\User\\{$modelName}Controller;

Route::prefix('" . strtolower($entityShortName) . "')->name('" . strtolower($entityShortName) . ".')->group(function () {
    Route::get('/', [{$modelName}Controller::class, 'index'])->name('index');
    Route::post('/', [{$modelName}Controller::class, 'store'])->name('store');
    Route::put('/{item}', [{$modelName}Controller::class, 'update'])->name('update');
    Route::delete('/{item}', [{$modelName}Controller::class, 'destroy'])->name('destroy');
});
";
    file_put_contents($routesPath, $routesContent);

    // Prepare entity configuration data to be saved in JSON.
    $configData = [
        'model_name' => $modelName,
        'table_name' => $tableName,
        'entity_short_name' => $entityShortName,
        'entity_display_title' => $entityDisplayTitle,
        'fields' => $fields,
        'created_at' => date('Y-m-d H:i:s')
    ];
    file_put_contents($configPath, json_encode($configData, JSON_PRETTY_PRINT));

    // Generate Blade view files using a Bootstrap card layout.
    // 1. index.blade.php (List view using DataTables)
    $indexView = "<!-- resources/views/frontend/" . strtolower($entityShortName) . "/index.blade.php -->
@extends('frontend.layouts.app')

@push('after-styles')
    <link rel=\"stylesheet\" href=\"https://cdn.datatables.net/2.0.8/css/dataTables.dataTables.css\">
    <link rel=\"stylesheet\" href=\"https://cdn.datatables.net/buttons/3.0.2/css/buttons.dataTables.css\">
    <link rel=\"stylesheet\" href=\"https://cdn.datatables.net/searchbuilder/1.7.1/css/searchBuilder.dataTables.css\">
    <link rel=\"stylesheet\" href=\"https://cdn.datatables.net/datetime/1.5.2/css/dataTables.dateTime.min.css\">
@endpush

@section('title', '{$modelName} List')

@section('content')
<div class=\"container-fluid\">
    <div class=\"row mb-3\">
        <div class=\"col-12\">
            <a href=\"{{ route('frontend." . strtolower($entityShortName) . ".create') }}\" class=\"btn btn-primary\">Add New {$modelName}</a>
        </div>
    </div>
    <div class=\"row\">
        <div class=\"col-12\">
            <div class=\"card\">
                <div class=\"card-header\">
                    <h3 class=\"card-title\">{$modelName} List</h3>
                </div>
                <div class=\"card-body\">
                    <table class=\"table table-bordered\">
                        <thead>
                            <tr>
                                <th>#</th>
                                " . implode("\n                                ", array_map(function ($field) {
            $view = htmlspecialchars($field['view_name'], ENT_QUOTES, 'UTF-8');
            return '<th>' . $view . '</th>';
        }, $fields)) . "
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach(\$" . strtolower($entityShortName) . "s as \$key => \$" . strtolower($entityShortName) . ")
                                <tr>
                                    <td>{{ \$key + 1 }}</td>
                                    " . implode("\n", array_map(function ($field) use ($entityShortName) {
            $col = htmlspecialchars($field['column_name'], ENT_QUOTES, 'UTF-8');
            return '<td>{{ $' . strtolower($entityShortName) . '->' . $col . ' }}</td>';
        }, $fields)) . "

            <td>
                                        <button type=\"button\" class=\"btn btn-sm btn-primary\" data-toggle=\"modal\"
                                                  data-target=\"#" . strtolower($entityShortName) . "-modal-{{ $" . strtolower($entityShortName) . "->id }}-Id\">
                                              Edit
                                              </button>
                                              
                                              <div class=\"modal fade\" id=\"" . strtolower($entityShortName) . "-modal-{{ $" . strtolower($entityShortName) . "->id }}-Id\" tabindex=\"-1\" role=\"dialog\"
                                               aria-labelledby=\"" . strtolower($entityShortName) . "-modal-{{ $" . strtolower($entityShortName) . "->id }}-TitleId\" aria-hidden=\"true\">
                                              <div class=\"modal-dialog\" role=\"document\">
                                                  <div class=\"modal-content\">
                                                      <div class=\"modal-header\">
                                                          <h4 class=\"modal-title\" id=\"" . strtolower($entityShortName) . "-modal-{{ $" . strtolower($entityShortName) . "->id }}-TitleId\"></h4>
                                                          <button type=\"button\" class=\"close\" data-dismiss=\"modal\"
                                                                  aria-label=\"Close\">
                                                              <span aria-hidden=\"true\">&times;</span>
                                                          </button>
                                                      </div>
                                                      <div class=\"modal-body\">
                                                          <div class=\"container-fluid\">
                                                              <div class=\"row\">
                                                                  <div class=\"col-12\">
                                                                      <div class=\"card\">
                                                                          <div class=\"card-header\">
                                                                              <h3 class=\"card-title\">Edit {$modelName}</h3>
                                                                          </div>
                                                                          <div class=\"card-body\">
                                                                           <form action=\"{{ route('frontend." . strtolower($entityShortName) . ".update', \$" . strtolower($entityShortName) . "->id) }}\" method=\"POST\" onsubmit=\"return confirm('Are you sure you want to update this?')\">
                        @csrf
                        @method('PUT')
";
    foreach ($fields as $field) {
        $col = htmlspecialchars($field['column_name'], ENT_QUOTES, 'UTF-8');
        $view = htmlspecialchars($field['view_name'], ENT_QUOTES, 'UTF-8');
        $dataType = $field['data_type'];
        if ($dataType === 'rich_text') {
            $editForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <textarea class=\"form-control\" name=\"{$col}\" id=\"{$col}\" rows=\"5\">{{ \$" . strtolower($entityShortName) . "->{$col} }}</textarea>
                            <small class=\"form-text text-muted\">Kindly explain in detail</small>
                        </div>
        ";
        } elseif ($dataType === 'long_text') {
            $editForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <textarea class=\"form-control\" name=\"{$col}\" id=\"{$col}\" rows=\"5\">{{ \$" . strtolower($entityShortName) . "->{$col} }}</textarea>
                        </div>
        ";
        } elseif ($dataType === 'integer') {
            $editForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <input type=\"number\" name=\"{$col}\" id=\"{$col}\" class=\"form-control\" value=\"{{ \$" . strtolower($entityShortName) . "->{$col} }}\" required>
                        </div>
        ";
        } elseif ($dataType === 'money') {
            $editForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <input type=\"number\" step=\"0.01\" name=\"{$col}\" id=\"{$col}\" class=\"form-control\" value=\"{{ \$" . strtolower($entityShortName) . "->{$col} }}\" required>
                        </div>
        ";
        } elseif ($dataType === 'date') {
            $editForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <input type=\"date\" name=\"{$col}\" id=\"{$col}\" class=\"form-control\" value=\"{{ \$item->{$col} }}\" required>
                        </div>
        ";
        } elseif ($dataType === 'datetime') {
            $editForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <input type=\"datetime-local\" name=\"{$col}\" id=\"{$col}\" class=\"form-control\" value=\"{{ \$item->{$col} }}\" required>
                        </div>
        ";
        } elseif ($dataType === 'boolean') {
            $editForm .= "
                        <div class=\"form-group form-check\">
                            <input type=\"hidden\" name=\"{$col}\" value=\"0\">
                            <input type=\"checkbox\" class=\"form-check-input\" name=\"{$col}\" id=\"{$col}\" value=\"1\" {{ \$item->{$col} ? 'checked' : '' }}>
                            <label class=\"form-check-label\" for=\"{$col}\">{$view}</label>
                        </div>
        ";
        } elseif ($dataType === 'select') {
            $editForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <select name=\"{$col}\" id=\"{$col}\" class=\"form-control\" required>
                                <option value=\"\">-- Select --</option>
                                <option value=\"Option1\" {{ \$item->{$col} == 'Option1' ? 'selected' : '' }}>Option1</option>
                                <option value=\"Option2\" {{ \$item->{$col} == 'Option2' ? 'selected' : '' }}>Option2</option>
                                <option value=\"Option3\" {{ \$item->{$col} == 'Option3' ? 'selected' : '' }}>Option3</option>
                            </select>
                        </div>
        ";
        } elseif ($dataType === 'radio') {
            $editForm .= "
                        <div class=\"form-group\">
                            <label>{$view}</label><br>
                            <div class=\"form-check form-check-inline\">
                                <input class=\"form-check-input\" type=\"radio\" name=\"{$col}\" id=\"{$col}_1\" value=\"Option1\" {{ \$item->{$col} == 'Option1' ? 'checked' : '' }} required>
                                <label class=\"form-check-label\" for=\"{$col}_1\">Option1</label>
                            </div>
                            <div class=\"form-check form-check-inline\">
                                <input class=\"form-check-input\" type=\"radio\" name=\"{$col}\" id=\"{$col}_2\" value=\"Option2\" {{ \$item->{$col} == 'Option2' ? 'checked' : '' }}>
                                <label class=\"form-check-label\" for=\"{$col}_2\">Option2</label>
                            </div>
                            <div class=\"form-check form-check-inline\">
                                <input class=\"form-check-input\" type=\"radio\" name=\"{$col}\" id=\"{$col}_3\" value=\"Option3\" {{ \$item->{$col} == 'Option3' ? 'checked' : '' }}>
                                <label class=\"form-check-label\" for=\"{$col}_3\">Option3</label>
                            </div>
                        </div>
        ";
        } elseif ($dataType === 'foreign') {
            $relatedModel = !empty($field['related_model']) ? trim($field['related_model']) : 'RelatedModel';
            $displayColumn = !empty($field['display_column']) ? trim($field['display_column']) : 'name';
            $editForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <select name=\"{$col}\" id=\"{$col}\" class=\"form-control\" required>
                                <option value=\"\">-- Select --</option>
                                @foreach(\App\Models\\{$relatedModel}::all() as \$option)
                                    <option value=\"{{ \$option->id }}\" {{ \$item->{$col} == \$option->id ? 'selected' : '' }}>{{ \$option->{$displayColumn} }}</option>
                                @endforeach
                            </select>
                        </div>
        ";
        } else {
            $editForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <input type=\"text\" name=\"{$col}\" id=\"{$col}\" class=\"form-control\" value=\"{{ \$item->{$col} }}\" required>
                        </div>
        ";
        }
    }
    $editForm .= "
                        <button type=\"submit\" class=\"btn btn-primary\">Update</button>
                        <a href=\"{{ route('frontend." . strtolower($entityShortName) . ".index') }}\" class=\"btn btn-secondary\">Cancel</a>
                    </form>   
                                                                          </div>
                                                                      </div>
                                                                  </div>
                                                              </div>
                                                          </div>
                                                      </div>
                                                      <div class=\"modal-footer\">
                                                          <button type=\"button\" class=\"btn btn-secondary\"
                                                                  data-dismiss=\"modal\">Close
                                                          </button>
                                                          <button type=\"button\" class=\"btn btn-primary\">Save</button>
                                                      </div>
                                                  </div>
                                              </div>
                                          </div>

                                        <form action=\"{{ route('frontend." . strtolower($entityShortName) . ".destroy', \$" . strtolower($entityShortName) . "->id) }}\" method=\"POST\" class=\"d-inline\" onsubmit=\"return confirm('Are you sure?')\">
                                            @csrf
                                            @method('DELETE')
                                            <button type=\"submit\" class=\"btn btn-sm btn-danger\">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('after-scripts')
    <script src=\"https://cdn.datatables.net/2.0.8/js/dataTables.js\"></script>
    <script src=\"https://cdn.datatables.net/buttons/3.0.2/js/dataTables.buttons.js\"></script>
    <script src=\"https://cdn.datatables.net/buttons/3.0.2/js/buttons.dataTables.js\"></script>
    <script src=\"https://cdn.datatables.net/searchbuilder/1.7.1/js/dataTables.searchBuilder.js\"></script>
    <script src=\"https://cdn.datatables.net/searchbuilder/1.7.1/js/searchBuilder.dataTables.js\"></script>
    <script src=\"https://cdn.datatables.net/datetime/1.5.2/js/dataTables.dateTime.min.js\"></script>
    <script src=\"https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js\"></script>
    <script src=\"https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js\"></script>
    <script src=\"https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js\"></script>
    <script src=\"https://cdn.datatables.net/buttons/3.0.2/js/buttons.html5.min.js\"></script>
    <script src=\"https://cdn.datatables.net/buttons/3.0.2/js/buttons.print.min.js\"></script>
    <script>
        \$(document).ready(function () {
            var table = new DataTable('.table', {
                \"paging\": false,
                scrollY: 465,
                layout: {
                    top: {
                        searchBuilder: { }
                    },
                    topStart: {
                        buttons: ['copy', 'csv', 'excel', 'pdf', 'print']
                    }
                }
            });
        });
    </script>
@endpush
";
    file_put_contents($viewsDir . "index.blade.php", $indexView);

    // 2. create.blade.php (form view with CKEditor if rich_text field exists)
    $createForm = "<!-- resources/views/frontend/" . strtolower($entityShortName) . "/create.blade.php -->
@extends('frontend.layouts.app')

@section('title', 'Add New {$modelName}')

@section('content')
<div class=\"container-fluid\">
    <div class=\"row\">
        <div class=\"col-12\">
            <div class=\"card\">
                <div class=\"card-header\">
                    <h3 class=\"card-title\">Add New {$modelName}</h3>
                </div>
                <div class=\"card-body\">
                    <form action=\"{{ route('frontend." . strtolower($entityShortName) . ".store') }}\" method=\"POST\">
                        @csrf
";
    foreach ($fields as $field) {
        $col = htmlspecialchars($field['column_name'], ENT_QUOTES, 'UTF-8');
        $view = htmlspecialchars($field['view_name'], ENT_QUOTES, 'UTF-8');
        $dataType = $field['data_type'];
        if ($dataType === 'rich_text') {
            // Rich text field with CKEditor
            $createForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <textarea class=\"form-control\" name=\"{$col}\" id=\"{$col}\" rows=\"5\"></textarea>
                            <small class=\"form-text text-muted\">Kindly explain in detail</small>
                        </div>
        ";
        } elseif ($dataType === 'long_text') {
            // Plain textarea without CKEditor
            $createForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <textarea class=\"form-control\" name=\"{$col}\" id=\"{$col}\" rows=\"5\"></textarea>
                        </div>
        ";
        } elseif ($dataType === 'integer') {
            $createForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <input type=\"number\" name=\"{$col}\" id=\"{$col}\" class=\"form-control\" required>
                        </div>
        ";
        } elseif ($dataType === 'money') {
            $createForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <input type=\"number\" step=\"0.01\" name=\"{$col}\" id=\"{$col}\" class=\"form-control\" required>
                        </div>
        ";
        } elseif ($dataType === 'date') {
            $createForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <input type=\"date\" name=\"{$col}\" id=\"{$col}\" class=\"form-control\" required>
                        </div>
        ";
        } elseif ($dataType === 'datetime') {
            $createForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <input type=\"datetime-local\" name=\"{$col}\" id=\"{$col}\" class=\"form-control\" required>
                        </div>
        ";
        } elseif ($dataType === 'boolean') {
            // For checkbox, a hidden field is often added to send 0 when unchecked.
            $createForm .= "
                        <div class=\"form-group form-check\">
                            <input type=\"hidden\" name=\"{$col}\" value=\"0\">
                            <input type=\"checkbox\" class=\"form-check-input\" name=\"{$col}\" id=\"{$col}\" value=\"1\">
                            <label class=\"form-check-label\" for=\"{$col}\">{$view}</label>
                        </div>
        ";
        } elseif ($dataType === 'select') {
            // Dummy options for a select dropdown.
            $createForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <select name=\"{$col}\" id=\"{$col}\" class=\"form-control\" required>
                                <option value=\"\">-- Select --</option>
                                <option value=\"Option1\">Option1</option>
                                <option value=\"Option2\">Option2</option>
                                <option value=\"Option3\">Option3</option>
                            </select>
                        </div>
        ";
        } elseif ($dataType === 'radio') {
            // Dummy radio options.
            $createForm .= "
                        <div class=\"form-group\">
                            <label>{$view}</label><br>
                            <div class=\"form-check form-check-inline\">
                                <input class=\"form-check-input\" type=\"radio\" name=\"{$col}\" id=\"{$col}_1\" value=\"Option1\" required>
                                <label class=\"form-check-label\" for=\"{$col}_1\">Option1</label>
                            </div>
                            <div class=\"form-check form-check-inline\">
                                <input class=\"form-check-input\" type=\"radio\" name=\"{$col}\" id=\"{$col}_2\" value=\"Option2\">
                                <label class=\"form-check-label\" for=\"{$col}_2\">Option2</label>
                            </div>
                            <div class=\"form-check form-check-inline\">
                                <input class=\"form-check-input\" type=\"radio\" name=\"{$col}\" id=\"{$col}_3\" value=\"Option3\">
                                <label class=\"form-check-label\" for=\"{$col}_3\">Option3</label>
                            </div>
                        </div>
        ";
        } elseif ($dataType === 'foreign') {
            // For a foreign key, we use the related table details.
            $relatedModel = !empty($field['related_model']) ? trim($field['related_model']) : 'RelatedModel';
            $displayColumn = !empty($field['display_column']) ? trim($field['display_column']) : 'name';
            $createForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <select name=\"{$col}\" id=\"{$col}\" class=\"form-control\" required>
                                <option value=\"\">-- Select --</option>
                                @foreach(\App\Models\\{$relatedModel}::all() as \$option)
                                    <option value=\"{{ \$option->id }}\">{{ \$option->{$displayColumn} }}</option>
                                @endforeach
                            </select>
                        </div>
        ";
        } else {
            // Default: use text input.
            $createForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <input type=\"text\" name=\"{$col}\" id=\"{$col}\" class=\"form-control\" required>
                        </div>
        ";
        }
    }

    $createForm .= "
                        <button type=\"submit\" class=\"btn btn-primary\">Save</button>
                        <a href=\"{{ route('frontend." . strtolower($entityShortName) . ".index') }}\" class=\"btn btn-secondary\">Cancel</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('after-scripts')
    <!-- Load CKEditor only if needed -->
    <script src=\"https://cdn.ckeditor.com/ckeditor5/36.0.1/classic/ckeditor.js\"></script>
    <script>
        document.querySelectorAll('textarea').forEach(function(textarea) {
            if(textarea.id) {
                ClassicEditor.create(textarea).catch(error => { console.error(error); });
            }
        });
    </script>
@endpush
";

    file_put_contents($viewsDir . "create.blade.php", $createForm);

    // 3. edit.blade.php – similar to create but with pre-populated values.
    $editForm = "<!-- resources/views/frontend/" . strtolower($entityShortName) . "/edit.blade.php -->
@extends('frontend.layouts.app')

@section('title', 'Edit {$modelName}')

@section('content')
<div class=\"container-fluid\">
    <div class=\"row\">
        <div class=\"col-12\">
            <div class=\"card\">
                <div class=\"card-header\">
                    <h3 class=\"card-title\">Edit {$modelName}</h3>
                </div>
                <div class=\"card-body\">
                    <form action=\"{{ route('frontend." . strtolower($entityShortName) . ".update', \$item->id) }}\" method=\"POST\" onsubmit=\"return confirm('Are you sure you want to update this?')\">
                        @csrf
                        @method('PUT')
";
    foreach ($fields as $field) {
        $col = htmlspecialchars($field['column_name'], ENT_QUOTES, 'UTF-8');
        $view = htmlspecialchars($field['view_name'], ENT_QUOTES, 'UTF-8');
        $dataType = $field['data_type'];
        if ($dataType === 'rich_text') {
            $editForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <textarea class=\"form-control\" name=\"{$col}\" id=\"{$col}\" rows=\"5\">{{ \$item->{$col} }}</textarea>
                            <small class=\"form-text text-muted\">Kindly explain in detail</small>
                        </div>
        ";
        } elseif ($dataType === 'long_text') {
            $editForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <textarea class=\"form-control\" name=\"{$col}\" id=\"{$col}\" rows=\"5\">{{ \$item->{$col} }}</textarea>
                        </div>
        ";
        } elseif ($dataType === 'integer') {
            $editForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <input type=\"number\" name=\"{$col}\" id=\"{$col}\" class=\"form-control\" value=\"{{ \$item->{$col} }}\" required>
                        </div>
        ";
        } elseif ($dataType === 'money') {
            $editForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <input type=\"number\" step=\"0.01\" name=\"{$col}\" id=\"{$col}\" class=\"form-control\" value=\"{{ \$item->{$col} }}\" required>
                        </div>
        ";
        } elseif ($dataType === 'date') {
            $editForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <input type=\"date\" name=\"{$col}\" id=\"{$col}\" class=\"form-control\" value=\"{{ \$item->{$col} }}\" required>
                        </div>
        ";
        } elseif ($dataType === 'datetime') {
            $editForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <input type=\"datetime-local\" name=\"{$col}\" id=\"{$col}\" class=\"form-control\" value=\"{{ \$item->{$col} }}\" required>
                        </div>
        ";
        } elseif ($dataType === 'boolean') {
            $editForm .= "
                        <div class=\"form-group form-check\">
                            <input type=\"hidden\" name=\"{$col}\" value=\"0\">
                            <input type=\"checkbox\" class=\"form-check-input\" name=\"{$col}\" id=\"{$col}\" value=\"1\" {{ \$item->{$col} ? 'checked' : '' }}>
                            <label class=\"form-check-label\" for=\"{$col}\">{$view}</label>
                        </div>
        ";
        } elseif ($dataType === 'select') {
            $editForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <select name=\"{$col}\" id=\"{$col}\" class=\"form-control\" required>
                                <option value=\"\">-- Select --</option>
                                <option value=\"Option1\" {{ \$item->{$col} == 'Option1' ? 'selected' : '' }}>Option1</option>
                                <option value=\"Option2\" {{ \$item->{$col} == 'Option2' ? 'selected' : '' }}>Option2</option>
                                <option value=\"Option3\" {{ \$item->{$col} == 'Option3' ? 'selected' : '' }}>Option3</option>
                            </select>
                        </div>
        ";
        } elseif ($dataType === 'radio') {
            $editForm .= "
                        <div class=\"form-group\">
                            <label>{$view}</label><br>
                            <div class=\"form-check form-check-inline\">
                                <input class=\"form-check-input\" type=\"radio\" name=\"{$col}\" id=\"{$col}_1\" value=\"Option1\" {{ \$item->{$col} == 'Option1' ? 'checked' : '' }} required>
                                <label class=\"form-check-label\" for=\"{$col}_1\">Option1</label>
                            </div>
                            <div class=\"form-check form-check-inline\">
                                <input class=\"form-check-input\" type=\"radio\" name=\"{$col}\" id=\"{$col}_2\" value=\"Option2\" {{ \$item->{$col} == 'Option2' ? 'checked' : '' }}>
                                <label class=\"form-check-label\" for=\"{$col}_2\">Option2</label>
                            </div>
                            <div class=\"form-check form-check-inline\">
                                <input class=\"form-check-input\" type=\"radio\" name=\"{$col}\" id=\"{$col}_3\" value=\"Option3\" {{ \$item->{$col} == 'Option3' ? 'checked' : '' }}>
                                <label class=\"form-check-label\" for=\"{$col}_3\">Option3</label>
                            </div>
                        </div>
        ";
        } elseif ($dataType === 'foreign') {
            $relatedModel = !empty($field['related_model']) ? trim($field['related_model']) : 'RelatedModel';
            $displayColumn = !empty($field['display_column']) ? trim($field['display_column']) : 'name';
            $editForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <select name=\"{$col}\" id=\"{$col}\" class=\"form-control\" required>
                                <option value=\"\">-- Select --</option>
                                @foreach(\App\Models\\{$relatedModel}::all() as \$option)
                                    <option value=\"{{ \$option->id }}\" {{ \$item->{$col} == \$option->id ? 'selected' : '' }}>{{ \$option->{$displayColumn} }}</option>
                                @endforeach
                            </select>
                        </div>
        ";
        } else {
            $editForm .= "
                        <div class=\"form-group\">
                            <label for=\"{$col}\">{$view}</label>
                            <input type=\"text\" name=\"{$col}\" id=\"{$col}\" class=\"form-control\" value=\"{{ \$item->{$col} }}\" required>
                        </div>
        ";
        }
    }
    $editForm .= "
                        <button type=\"submit\" class=\"btn btn-primary\">Update</button>
                        <a href=\"{{ route('frontend." . strtolower($entityShortName) . ".index') }}\" class=\"btn btn-secondary\">Cancel</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('after-scripts')
    <script src=\"https://cdn.ckeditor.com/ckeditor5/36.0.1/classic/ckeditor.js\"></script>
    <script>
        document.querySelectorAll('textarea').forEach(function(textarea) {
            ClassicEditor.create(textarea).catch(error => { console.error(error); });
        });
    </script>
@endpush
";

    file_put_contents($viewsDir . "edit.blade.php", $editForm);

    // 4. show.blade.php – display details in a Bootstrap card.
    $showView = "<!-- resources/views/frontend/" . strtolower($entityShortName) . "/show.blade.php -->
@extends('frontend.layouts.app')

@section('title', '{$modelName} Details')

@section('content')
<div class=\"container-fluid\">
    <div class=\"row\">
        <div class=\"col-12\">
            <div class=\"card\">
                <div class=\"card-header\">
                    <h3 class=\"card-title\">{$modelName} Details</h3>
                </div>
                <div class=\"card-body\">
                    ";
    foreach ($fields as $field) {
        $col = htmlspecialchars($field['column_name'], ENT_QUOTES, 'UTF-8');
        $view = htmlspecialchars($field['view_name'], ENT_QUOTES, 'UTF-8');
        $dataType = $field['data_type'];

        if ($dataType === 'rich_text') {
            // Render rich text as HTML.
            $showView .= "<p><strong>{$view}:</strong> {!! \$item->{$col} !!}</p>\n";
        } elseif ($dataType === 'integer' || $dataType === 'money') {
            // Use the custom function checkIntNumber().
            $showView .= "<p><strong>{$view}:</strong> {{ checkIntNumber(\$item->{$col}) }}</p>\n";
        } elseif ($dataType === 'foreign') {
            // Determine the display column (default to 'name').
            $displayColumn = 'name';
            if (!empty($field['display_column'])) {
                $displayColumn = htmlspecialchars(trim($field['display_column']), ENT_QUOTES, 'UTF-8');
            }
            // Use the relationship method; note that we assume the relationship method
            // is named as the column name plus "Relation".
            $showView .= "<p><strong>{$view}:</strong> {{ \$item->{$col}Relation ? \$item->{$col}Relation->{$displayColumn} : '' }}</p>\n";
        } else {
            // For all other types, display the value normally.
            $showView .= "<p><strong>{$view}:</strong> {{ \$item->{$col} }}</p>\n";
        }
    }
    $showView .= "
                    <a href=\"{{ route('frontend." . strtolower($entityShortName) . ".index') }}\" class=\"btn btn-secondary\">Back</a>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
";
    file_put_contents($viewsDir . "show.blade.php", $showView);
}

