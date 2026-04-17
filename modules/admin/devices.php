<?php
require_once __DIR__ . '/../../includes/auth.php';
requireDeviceManager();

$page_title = 'Device Management';
include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row mb-3">
        <div class="col">
            <h2><i class="fas fa-laptop"></i> Device Management</h2>
        </div>
        <div class="col-auto">
            <?php if (in_array($_SESSION['role'] ?? '', ['admin'], true)): ?>
            <a href="../admin/device_permissions.php" class="btn btn-outline-secondary me-2">
                <i class="fas fa-user-shield"></i> Device Permissions
            </a>
            <?php endif; ?>
            <button class="btn btn-primary" onclick="showAddDeviceModal()">
                <i class="fas fa-plus"></i> Add Device
            </button>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h5 class="card-title">Total Devices</h5>
                    <h2 id="totalDevices">0</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <h5 class="card-title">Available</h5>
                    <h2 id="availableDevices">0</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white">
                <div class="card-body">
                    <h5 class="card-title">Assigned</h5>
                    <h2 id="assignedDevices">0</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <h5 class="card-title">Pending Requests</h5>
                    <h2 id="pendingRequests">0</h2>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs" role="tablist">
        <li class="nav-item">
            <a class="nav-link active" data-bs-toggle="tab" href="#devicesTab">Devices</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#requestsTab">Switch Requests</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#historyTab">Rotation History</a>
        </li>
    </ul>

    <div class="tab-content mt-3">
        <!-- Devices Tab -->
        <div id="devicesTab" class="tab-pane fade show active">
            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover" id="devicesTable">
                            <thead>
                                <tr>
                                    <th>Device Name</th>
                                    <th>Type</th>
                                    <th>Model</th>
                                    <th>Version</th>
                                    <th>Ownership</th>
                                    <th>Storage</th>
                                    <th>Charger/Wire</th>
                                    <th>Status</th>
                                    <th>Assigned To</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Requests Tab -->
        <div id="requestsTab" class="tab-pane fade">
            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover" id="requestsTable">
                            <thead>
                                <tr>
                                    <th>Device</th>
                                    <th>Requested By</th>
                                    <th>Current Holder</th>
                                    <th>Reason</th>
                                    <th>Requested At</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Rotation History Tab -->
        <div id="historyTab" class="tab-pane fade">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-history"></i> Device Rotation History</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <input type="text" class="form-control" id="searchHistory" placeholder="Search history..." onkeyup="filterHistory()">
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover table-striped" id="rotationHistoryTable">
                            <thead class="table-dark">
                                <tr>
                                    <th>Date & Time</th>
                                    <th>Device</th>
                                    <th>From User</th>
                                    <th>To User</th>
                                    <th>Rotated By</th>
                                    <th>Reason</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Device Modal -->
<div class="modal fade" id="deviceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deviceModalTitle">Add Device</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="deviceForm">
                    <input type="hidden" id="deviceId" name="device_id">
                    
                    <div class="mb-3">
                        <label class="form-label">Device Name *</label>
                        <input type="text" class="form-control" id="deviceName" name="device_name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Device Type *</label>
                        <select class="form-select" id="deviceType" name="device_type" required>
                            <option value="Android">Android</option>
                            <option value="iOS">iOS</option>
                            <option value="Mac">Mac</option>
                            <option value="Windows">Windows</option>
                            <option value="BT Keyboard">BT Keyboard</option>
                            <option value="Mouse">Mouse</option>
                            <option value="Tablet">Tablet</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Model</label>
                        <input type="text" class="form-control" id="model" name="model">
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Storage Capacity (GB)</label>
                            <input type="number" class="form-control" id="storageCapacity" name="storage_capacity" min="0" placeholder="e.g. 128">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Charger / Wire Details</label>
                            <input type="text" class="form-control" id="chargerWire" name="charger_wire" placeholder="e.g. Yes, 65W, or Original">
                        </div>
                    </div>

                    
                    <div class="mb-3">
                        <label class="form-label">Version</label>
                        <input type="text" class="form-control" id="version" name="version">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Serial Number</label>
                        <input type="text" class="form-control" id="serialNumber" name="serial_number">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Purchase Date</label>
                        <input type="date" class="form-control" id="purchaseDate" name="purchase_date">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="Available">Available</option>
                            <option value="Assigned">Assigned</option>
                            <option value="Maintenance">Maintenance</option>
                            <option value="Retired">Retired</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Ownership Type *</label>
                        <select class="form-select" id="ownershipType" name="ownership_type" required onchange="toggleLeaseOwner(this.value)">
                            <option value="Owned">Owned</option>
                            <option value="Leased">Leased</option>
                        </select>
                    </div>

                    <div class="mb-3 d-none" id="leaseOwnerWrap">
                        <label class="form-label">Lease Owner / Vendor Name *</label>
                        <input type="text" class="form-control" id="leaseOwner" name="lease_owner" placeholder="e.g. ABC Rentals Pvt Ltd">
                    </div>

                    <div class="mb-3 d-none" id="editAssignWrap">
                        <label class="form-label">Assign To</label>
                        <select class="form-select" id="editAssignUserId" name="assigned_user_id">
                            <option value="">-- Keep Current Assignment --</option>
                        </select>
                        <small class="text-muted">In edit mode, you can select a user here to reassign the device.</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveDevice()">Save</button>
            </div>
        </div>
    </div>
</div>

<!-- Assign Device Modal -->
<div class="modal fade" id="assignModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Assign Device</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="assignForm">
                    <input type="hidden" id="assignDeviceId" name="device_id">
                    
                    <div class="mb-3">
                        <label class="form-label">Assign To *</label>
                        <select class="form-select" id="assignUserId" name="user_id" required></select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" id="assignNotes" name="notes" rows="3"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="assignDeviceBtn">Assign</button>
            </div>
        </div>
    </div>
</div>

<script src="<?php echo htmlspecialchars(getBaseDir(), ENT_QUOTES, 'UTF-8'); ?>/assets/js/devices.js"></script>

<!-- Respond to Request Modal -->
<div class="modal fade" id="respondModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Respond to Request</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="respondForm">
                    <input type="hidden" id="requestId" name="request_id">
                    <input type="hidden" id="responseAction" name="response">
                    
                    <div class="mb-3">
                        <label class="form-label">Response Notes</label>
                        <textarea class="form-control" id="responseNotes" name="response_notes" rows="3"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="respondToRequest('Rejected')">Reject</button>
                <button type="button" class="btn btn-success" onclick="respondToRequest('Approved')">Approve</button>
            </div>
        </div>
    </div>
</div>


<?php include '../../includes/footer.php'; 
