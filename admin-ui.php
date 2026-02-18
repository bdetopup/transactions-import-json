<?php
if (!defined('pp_allowed_access')) {
    die('Direct access not allowed');
}

global $conn, $setting, $auth_id;
global $db_host, $db_user, $db_pass, $db_name, $db_prefix, $mode;

$plugin_slug = 'transactions-import-json';
$settings = pp_get_plugin_setting($plugin_slug);
$setting = pp_get_settings();

// Get the plugin directory URL
$plugin_dir = dirname(__DIR__);
$plugin_url = str_replace($_SERVER['DOCUMENT_ROOT'], '', $plugin_dir);

// Function to dynamically find pp-config.php
function find_pp_config(): ?string
{
    $start = __DIR__;
    for ($i = 0; $i < 8; $i++) {
        $root = dirname($start, $i + 1);
        $cfg = $root . '/pp-config.php';
        if (is_file($cfg) && is_readable($cfg)) {
            return realpath($cfg);
        }
    }
    return null;
}

// Find and include the configuration file
$config_path = find_pp_config();
if ($config_path === null) {
    die('Could not find pp-config.php file');
}

require_once $config_path;

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Try updating database
if (!isset($conn)) {
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($conn->connect_error) {
        $error = "Connection failed: " . $conn->connect_error;
    }
    if (!$conn->query("SET NAMES utf8")) {
        $error = "Set names failed: " . $conn->error;
    }
    if (!empty($db_prefix)) {
        if (!$conn->query("SET sql_mode = ''")) {
            $error = "Set sql_mode failed: " . $conn->error;
        }
    }
}

if (isset($conn) && !$conn->connect_error) {
    $auth_id = uniqid();
    $sql = "UPDATE {$db_prefix}plugins SET plugin_array = '{\"auth_id\":\"$auth_id\"}' WHERE plugin_slug = '{$plugin_slug}'";
    $result = $conn->query($sql);
}

// Fetch active payment gateway plugins
$payment_methods = [];
if (isset($conn) && !$conn->connect_error) {
    $sql = "SELECT * FROM {$db_prefix}plugins WHERE status = 'active' AND plugin_dir = 'payment-gateway' AND JSON_extract(plugin_array, '$.status') = 'enable'";
    $result = $conn->query($sql);

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $plugin_array = json_decode($row['plugin_array'], true);
            $payment_methods[] = [
                'name' => $row['plugin_name'],
                'slug' => $row['plugin_slug'],
                'currency' => isset($plugin_array['currency']) ? $plugin_array['currency'] : null
            ];
        }
    }
}

// Get source and target file info
$source_file = isset($_GET['source']) ? basename($_GET['source']) : 'pp_transaction.json';
$target_file = isset($_GET['target']) ? basename($_GET['target']) : 'pp_transaction (1).json';

?>

<?php if (isset($message)) {
    echo $message;
} ?>

<div class="d-flex flex-column gap-4">
  <!-- Page Header -->
  <div class="page-header">
    <div class="row align-items-end">
      <div class="col-sm mb-2 mb-sm-0 d-flex align-items-center gap-2">
        <h1 class="page-header-title" style="margin-bottom: 4px;">Transactions Import From JSON</h1>
      </div>
      <div class="col-sm-auto">
        <button type="button" class="btn btn-primary" id="refreshData">
          <i class="bi bi-arrow-clockwise"></i> Reset Data
        </button>
      </div>
    </div>
  </div>
  
  <div class="row justify-content-center">
    <div class="col-md-10">
      <!-- Instructions Card -->
      <div class="card mb-4">
        <div class="card-header">
          <h5 class="card-title">How to Import Transactions</h5>
        </div>
        <div class="card-body">
          <div class="f-flex flex-column gap-2">
            <p class="form-label fw-bold">Follow these steps to import transactions from JSON files:</p>
            <p class="form-label">1. Prepare your source JSON file (old transactions) - Format: pp_transaction.json</p>
            <p class="form-label">2. Prepare your target JSON file (destination) - Format: pp_transaction (1).json</p>
            <p class="form-label">3. Upload both files below</p>
            <p class="form-label">4. Map payment methods from source to destination</p>
            <p class="form-label">5. Review and import the data</p>
          </div>
        </div>
      </div>

      <!-- File Upload Card -->
      <div class="card mb-4">
        <div class="card-header">
          <h5 class="card-title">Upload JSON Files</h5>
        </div>
        <div class="card-body">
          <form id="jsonUploadForm">
            <div class="row">
              <div class="col-md-6 mb-3">
                <label class="form-label fw-bold">Source File (Old Transactions)</label>
                <input type="file" class="form-control" id="sourceJsonFile" accept=".json" required>
                <div class="form-text">Upload pp_transaction.json file containing old transaction data.</div>
              </div>
              <div class="col-md-6 mb-3">
                <label class="form-label fw-bold">Target File (Destination)</label>
                <input type="file" class="form-control" id="targetJsonFile" accept=".json" required>
                <div class="form-text">Upload pp_transaction (1).json file where data will be imported.</div>
              </div>
            </div>
            <button type="submit" class="btn btn-primary">Process Files</button>
          </form>
          <div id="jsonResults" class="mt-4"></div>
        </div>
      </div>

      <div id="payment-method-mapping"></div>
      <div id="import-data"></div>
    </div>
  </div>

  <script>
    let sourceData = null;
    let targetData = null;
    let transformedData = null;

    // Function to extract table data from JSON structure
    function extractTableData(jsonData, tableName = 'pp_transaction') {
      if (Array.isArray(jsonData)) {
        for (const item of jsonData) {
          if (item.type === 'table' && item.name === tableName && item.data) {
            return item.data;
          }
        }
      }
      return jsonData; // Return as is if not in the expected format
    }

    // Function to process JSON files
    function processFiles(sourceFile, targetFile) {
      const sourceReader = new FileReader();
      const targetReader = new FileReader();

      Promise.all([
        new Promise((resolve) => {
          sourceReader.onload = function(e) {
            try {
              const jsonData = JSON.parse(e.target.result);
              sourceData = extractTableData(jsonData, 'pp_transaction');
              console.log('Source data extracted:', sourceData.length, 'records');
              resolve();
            } catch (error) {
              console.error('Error parsing source JSON:', error);
              document.getElementById('jsonResults').innerHTML = 
                '<div class="alert alert-danger">Error parsing source JSON file. Please check the file format.</div>';
              resolve();
            }
          };
          sourceReader.readAsText(sourceFile);
        }),
        new Promise((resolve) => {
          targetReader.onload = function(e) {
            try {
              const jsonData = JSON.parse(e.target.result);
              targetData = extractTableData(jsonData, 'pp_transaction');
              console.log('Target data extracted:', targetData.length, 'records');
              resolve();
            } catch (error) {
              console.error('Error parsing target JSON:', error);
              document.getElementById('jsonResults').innerHTML = 
                '<div class="alert alert-danger">Error parsing target JSON file. Please check the file format.</div>';
              resolve();
            }
          };
          targetReader.readAsText(targetFile);
        })
      ]).then(() => {
        if (sourceData && targetData) {
          // Extract unique payment methods from source
          const uniquePaymentMethods = [...new Set(sourceData
            .map(item => item.payment_method)
            .filter(method => method && method !== '--' && method !== '')
          )];

          if (uniquePaymentMethods.length === 0) {
            document.getElementById('jsonResults').innerHTML = 
              '<div class="alert alert-warning">No payment methods found in source data.</div>';
            return;
          }

          // Create payment method mapping UI
          createMappingUI(uniquePaymentMethods);
        }
      });
    }

    // Function to update dropdown options (prevent duplicate selection)
    function updateDropdownOptions() {
      const selects = document.querySelectorAll('.payment-method-select');
      const selectedValues = Array.from(selects)
        .map(select => select.value)
        .filter(value => value !== '');

      selects.forEach(select => {
        const currentValue = select.value;
        const options = select.querySelectorAll('option');

        options.forEach(option => {
          if (option.value === '' || option.value === currentValue) {
            option.disabled = false;
          } else {
            option.disabled = selectedValues.includes(option.value) && option.value !== currentValue;
          }
        });
      });
    }

    // Function to create mapping UI
    function createMappingUI(uniquePaymentMethods) {
      const paymentMethodMapping = document.getElementById('payment-method-mapping');
      paymentMethodMapping.innerHTML = `
        <div class="mt-4">
          <div class="card">
            <div class="card-header">
              <h5 class="card-title">Payment Method Mapping</h5>
              <p class="text-muted mb-0">Map source payment methods to destination payment methods</p>
            </div>
            <div class="card-body">
              <div class="table-responsive">
                <table class="table">
                  <thead>
                    <tr>
                      <th>Source Payment Method</th>
                      <th>Destination Payment Method</th>
                    </tr>
                  </thead>
                  <tbody>
                    ${uniquePaymentMethods.map(method => `
                      <tr>
                        <td>${method}</td>
                        <td>
                          <select class="form-select payment-method-select" data-original="${method}">
                            <option value="">Select Payment Method</option>
                            <?php foreach ($payment_methods as $pm): ?>
                            <option value="<?php echo htmlspecialchars($pm['slug']); ?>" 
                                    data-name="<?php echo htmlspecialchars($pm['name']); ?>" 
                                    data-currency="<?php echo htmlspecialchars($pm['currency']); ?>">
                              <?php echo htmlspecialchars($pm['name']); ?>
                            </option>
                            <?php endforeach; ?>
                          </select>
                        </td>
                      </tr>
                    `).join('')}
                  </tbody>
                </table>
              </div>
              <button class="btn btn-primary mt-3" id="applyMapping">Apply Mapping & Preview</button>
            </div>
          </div>
        </div>
      `;

      // Add change event listeners to all dropdowns
      document.querySelectorAll('.payment-method-select').forEach(select => {
        select.addEventListener('change', updateDropdownOptions);
      });

      updateDropdownOptions();

      // Handle apply mapping
      document.getElementById('applyMapping').addEventListener('click', function() {
        const applyMappingButton = this;
        applyMappingButton.disabled = true;
        applyMappingButton.innerHTML = '<i class="bi bi-arrow-right"></i> Mapping Applied';

        // Disable all dropdown selects
        document.querySelectorAll('.payment-method-select').forEach(select => {
          select.disabled = true;
        });

        const mappings = {};

        // Collect mappings
        document.querySelectorAll('.payment-method-select').forEach(select => {
          const originalMethod = select.dataset.original;
          const selectedOption = select.options[select.selectedIndex];
          
          if (selectedOption.value) {
            mappings[originalMethod] = {
              slug: selectedOption.value,
              name: selectedOption.dataset.name,
              currency: selectedOption.dataset.currency
            };
          }
        });

        // Transform data with mappings
        transformData(mappings);
      });
    }

    // Helper functions for data transformation
    function formatNumberFromText(text) {
      if (!text) return generateRandomNumber(10);
      
      let number = text.replace(/\D/g, "");
      while (number.length < 10) {
        number += Math.floor(Math.random() * 10);
      }
      if (number.length > 12) {
        number = number.slice(0, 12);
      }
      return number;
    }

    function generateRandomNumber(length) {
      let number = '';
      for (let i = 0; i < length; i++) {
        number += Math.floor(Math.random() * 10);
      }
      return number;
    }

    function makeMediaUrl(path) {
      if (!path || path === '--') return '--';
      const filename = path.split('/').pop();
      return `${window.location.protocol}//${window.location.host}/pp-external/media/${filename}`;
    }

    function roundStringNumber(str) {
      const num = parseFloat(str) || 0;
      return Math.round(num).toString();
    }

    function normalizeNumber(str) {
      const num = parseFloat(str) || 0;
      return num === 0 ? "0" : num.toString();
    }

    function transformData(mappings) {
      // Filter and transform source data
      transformedData = sourceData
        .filter(item => item.transaction_status === 'completed' || 
                       item.transaction_status === 'pending' || 
                       item.transaction_status === 'refunded')
        .map(item => {
          const mapping = mappings[item.payment_method] || {};
          
          return {
            pp_id: formatNumberFromText(item.pp_id),
            c_id: '--',
            c_name: item.c_name || '--',
            c_email_mobile: item.c_email_mobile || '--',
            payment_method_id: mapping.slug || item.payment_method_id || '--',
            payment_method: mapping.name || item.payment_method || '--',
            payment_verify_way: item.payment_verify_id && item.payment_verify_id !== '--' ? 'id' : '--',
            payment_sender_number: item.payment_sender_number && item.payment_sender_number !== '--' ? item.payment_sender_number : '--',
            payment_verify_id: item.payment_verify_id || '--',
            transaction_amount: roundStringNumber(item.transaction_amount),
            transaction_fee: normalizeNumber(item.transaction_fee),
            transaction_refund_amount: normalizeNumber(item.transaction_refund_amount),
            transaction_refund_reason: item.transaction_refund_reason && item.transaction_refund_reason !== '--' ? item.transaction_refund_reason : '--',
            transaction_currency: mapping.currency || item.transaction_currency || 'BDT',
            transaction_redirect_url: item.transaction_redirect_url && item.transaction_redirect_url !== '--' ? item.transaction_redirect_url : '--',
            transaction_return_type: item.transaction_return_type || 'GET',
            transaction_cancel_url: item.transaction_cancel_url && item.transaction_cancel_url !== '--' ? item.transaction_cancel_url : '--',
            transaction_webhook_url: item.transaction_webhook_url && item.transaction_webhook_url !== '--' ? item.transaction_webhook_url : '--',
            transaction_metadata: item.transaction_metadata || '{}',
            transaction_status: item.transaction_status || 'pending',
            transaction_product_name: '--',
            transaction_product_description: '--',
            transaction_product_meta: '--',
            created_at: item.created_at || new Date().toISOString().slice(0, 19).replace('T', ' ')
          };
        });

      // Check for duplicates with target data
      const existingBrandIds = new Set(targetData.map(item => item.brand_id).filter(id => id));
      const existingRefs = new Set(targetData.map(item => item.ref).filter(ref => ref));
      
      const duplicates = transformedData.filter(item => 
        existingBrandIds.has(item.pp_id) || existingRefs.has(item.payment_verify_id)
      );

      const uniqueData = transformedData.filter(item => 
        !existingBrandIds.has(item.pp_id) && !existingRefs.has(item.payment_verify_id)
      );

      // Show preview
      showPreview(uniqueData, duplicates.length);
    }

    function showPreview(data, duplicateCount) {
      const importDataElement = document.getElementById('import-data');
      
      importDataElement.innerHTML = `
        <div class="mt-4">
          <div class="card">
            <div class="card-header">
              <h5 class="card-title">Import Preview</h5>
            </div>
            <div class="card-body">
              <div class="alert alert-info">
                <strong>Total records to import:</strong> ${data.length}<br>
                <strong>Duplicates skipped:</strong> ${duplicateCount}
              </div>
              
              <div class="table-responsive mb-3" style="max-height: 300px; overflow-y: auto;">
                <table class="table table-sm table-bordered">
                  <thead class="sticky-top bg-light">
                    <tr>
                      <th>PP ID</th>
                      <th>Customer</th>
                      <th>Amount</th>
                      <th>Payment Method</th>
                      <th>Status</th>
                      <th>Date</th>
                    </tr>
                  </thead>
                  <tbody>
                    ${data.slice(0, 10).map(item => `
                      <tr>
                        <td>${item.pp_id}</td>
                        <td>${item.c_name || '--'}</td>
                        <td>${item.transaction_amount} ${item.transaction_currency}</td>
                        <td>${item.payment_method}</td>
                        <td><span class="badge bg-${item.transaction_status === 'completed' ? 'success' : 
                                                          item.transaction_status === 'pending' ? 'warning' : 
                                                          item.transaction_status === 'refunded' ? 'info' : 'secondary'}">${item.transaction_status}</span></td>
                        <td>${item.created_at}</td>
                      </tr>
                    `).join('')}
                    ${data.length > 10 ? '<tr><td colspan="6" class="text-center">...</td></tr>' : ''}
                  </tbody>
                </table>
              </div>
              
              <div class="d-flex gap-2">
                <button class="btn btn-primary" id="startImport" ${data.length === 0 ? 'disabled' : ''}>
                  Start Import (${data.length} records)
                </button>
                <button class="btn btn-secondary" id="downloadPreview">
                  <i class="bi bi-download"></i> Download Preview
                </button>
              </div>
            </div>
          </div>
        </div>
      `;

      // Handle start import
      document.getElementById('startImport')?.addEventListener('click', function() {
        startImport(data);
      });

      // Handle download preview
      document.getElementById('downloadPreview')?.addEventListener('click', function() {
        const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'import_preview.json';
        a.click();
      });
    }

    function startImport(data) {
      const importButton = document.getElementById('startImport');
      const cardBody = importButton.closest('.card-body');

      importButton.disabled = true;
      importButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Importing...';

      // Remove any existing alerts
      const existingAlert = cardBody.querySelector('.alert:not(.alert-info)');
      if (existingAlert) existingAlert.remove();

      // Send to server
      fetch("<?php echo $plugin_url; ?>/views/insert.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ 
          data: data, 
          auth_id: "<?php echo $auth_id; ?>" 
        })
      })
      .then(res => res.json())
      .then(response => {
        if (response.status === 'success') {
          importButton.remove();
          
          const alertClass = response.status === 'success' ? 'alert-success' : 'alert-danger';
          const message = `
            <div class="alert ${alertClass} mt-3" role="alert">
              <strong>Import Complete!</strong><br>
              Successfully imported ${response.inserted} out of ${response.total} records.
              ${response.duplicates ? `<br>Skipped ${response.duplicates} duplicate records.` : ''}
            </div>`;
          cardBody.innerHTML += message;
        } else {
          importButton.disabled = false;
          importButton.innerHTML = 'Start Import';

          const errorAlert = document.createElement('div');
          errorAlert.className = 'alert alert-danger mt-3';
          errorAlert.textContent = response.message || 'An error occurred during import.';
          cardBody.appendChild(errorAlert);
        }
      })
      .catch(error => {
        importButton.disabled = false;
        importButton.innerHTML = 'Start Import';

        const errorAlert = document.createElement('div');
        errorAlert.className = 'alert alert-danger mt-3';
        errorAlert.textContent = 'Connection error. Please try again.';
        cardBody.appendChild(errorAlert);
        console.error(error);
      });
    }

    // Handle form submission
    document.getElementById('jsonUploadForm').addEventListener('submit', function(e) {
      e.preventDefault();
      
      const sourceFile = document.getElementById('sourceJsonFile').files[0];
      const targetFile = document.getElementById('targetJsonFile').files[0];
      const submitButton = this.querySelector('button[type="submit"]');

      if (!sourceFile || !targetFile) {
        document.getElementById('jsonResults').innerHTML = 
          '<div class="alert alert-danger">Please select both source and target files.</div>';
        return;
      }

      if (!sourceFile.name.endsWith('.json') || !targetFile.name.endsWith('.json')) {
        document.getElementById('jsonResults').innerHTML = 
          '<div class="alert alert-danger">Please upload valid JSON files.</div>';
        return;
      }

      // Disable inputs
      document.getElementById('sourceJsonFile').disabled = true;
      document.getElementById('targetJsonFile').disabled = true;
      submitButton.disabled = true;
      submitButton.innerHTML = '<i class="bi bi-arrow-right"></i> Processing...';

      document.getElementById('jsonResults').innerHTML = 
        '<div class="alert alert-info">Processing files...</div>';

      processFiles(sourceFile, targetFile);
    });

    // Handle refresh button
    document.getElementById('refreshData').addEventListener('click', function() {
      const button = this;
      button.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Loading...';
      button.disabled = true;
      window.location.reload();
    });
  </script>

  <style>
    .payment-method-select option:disabled {
      color: #999;
      background-color: #f5f5f5;
    }
    .sticky-top {
      position: sticky;
      top: 0;
      background: white;
      z-index: 10;
    }
    .table-responsive {
      border: 1px solid #dee2e6;
      border-radius: 0.25rem;
    }
  </style>
</div>