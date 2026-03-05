import { useState } from 'react';
import Button from '../Common/Button';
import './Filters.css';

const Filters = ({ onFilterChange, issueStatuses = [] }) => {
  const [filters, setFilters] = useState({
    search: '',
    status: '',
    severity: '',
    priority: '',
  });

  const [showFilters, setShowFilters] = useState(false);

  const handleChange = (field, value) => {
    const newFilters = { ...filters, [field]: value };
    setFilters(newFilters);
    onFilterChange(newFilters);
  };

  const handleReset = () => {
    const resetFilters = {
      search: '',
      status: '',
      severity: '',
      priority: '',
    };
    setFilters(resetFilters);
    onFilterChange(resetFilters);
  };

  return (
    <div className="filters-container mb-3">
      <div className="d-flex gap-2 align-items-center mb-2">
        {/* Search */}
        <div className="flex-grow-1">
          <input
            type="text"
            className="form-control"
            placeholder="Search issues..."
            value={filters.search}
            onChange={(e) => handleChange('search', e.target.value)}
          />
        </div>

        {/* Toggle Filters Button */}
        <Button
          variant="outline-secondary"
          icon={`fas fa-filter`}
          onClick={() => setShowFilters(!showFilters)}
        >
          {showFilters ? 'Hide' : 'Show'} Filters
        </Button>

        {/* Reset Button */}
        {(filters.search || filters.status || filters.severity || filters.priority) && (
          <Button
            variant="outline-danger"
            icon="fas fa-times"
            onClick={handleReset}
          >
            Reset
          </Button>
        )}
      </div>

      {/* Advanced Filters */}
      {showFilters && (
        <div className="row g-2">
          <div className="col-md-4">
            <label className="form-label small">Status</label>
            <select
              className="form-select form-select-sm"
              value={filters.status}
              onChange={(e) => handleChange('status', e.target.value)}
            >
              <option value="">All Statuses</option>
              {issueStatuses.map(status => (
                <option key={status.id} value={status.id}>
                  {status.status_name}
                </option>
              ))}
            </select>
          </div>

          <div className="col-md-4">
            <label className="form-label small">Severity</label>
            <select
              className="form-select form-select-sm"
              value={filters.severity}
              onChange={(e) => handleChange('severity', e.target.value)}
            >
              <option value="">All Severities</option>
              <option value="Critical">Critical</option>
              <option value="High">High</option>
              <option value="Medium">Medium</option>
              <option value="Low">Low</option>
            </select>
          </div>

          <div className="col-md-4">
            <label className="form-label small">Priority</label>
            <select
              className="form-select form-select-sm"
              value={filters.priority}
              onChange={(e) => handleChange('priority', e.target.value)}
            >
              <option value="">All Priorities</option>
              <option value="Critical">Critical</option>
              <option value="High">High</option>
              <option value="Medium">Medium</option>
              <option value="Low">Low</option>
            </select>
          </div>
        </div>
      )}
    </div>
  );
};

export default Filters;
