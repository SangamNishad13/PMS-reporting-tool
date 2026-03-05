import { useState, useEffect } from 'react';
import useIssuesStore from '../../store/issuesStore';
import Button from '../Common/Button';
import './IssueModal.css';

const IssueModal = ({ isOpen, onClose, issue = null, projectId }) => {
  const { createIssue, updateIssue, issueStatuses, metadataFields, fetchIssueStatuses, fetchMetadataFields } = useIssuesStore();
  
  const [formData, setFormData] = useState({
    title: '',
    description: '',
    status_id: '',
    page_id: '',
    severity: '',
    priority: '',
    wcag_criteria: '',
    issue_type: '',
    environments: '',
  });
  
  const [errors, setErrors] = useState({});
  const [loading, setLoading] = useState(false);

  const isEditMode = !!issue;

  useEffect(() => {
    if (isOpen) {
      fetchIssueStatuses(projectId);
      fetchMetadataFields('accessibility');
      
      if (issue) {
        setFormData({
          title: issue.title || '',
          description: issue.description || '',
          status_id: issue.status_id || '',
          page_id: issue.page_id || '',
          severity: issue.severity || '',
          priority: issue.priority || '',
          wcag_criteria: issue.wcag_criteria || '',
          issue_type: issue.issue_type || '',
          environments: issue.environments || '',
        });
      } else {
        setFormData({
          title: '',
          description: '',
          status_id: '',
          page_id: '',
          severity: '',
          priority: '',
          wcag_criteria: '',
          issue_type: '',
          environments: '',
        });
      }
      setErrors({});
    }
  }, [isOpen, issue, fetchIssueStatuses, fetchMetadataFields, projectId]);

  const handleChange = (e) => {
    const { name, value } = e.target;
    setFormData(prev => ({ ...prev, [name]: value }));
    // Clear error for this field
    if (errors[name]) {
      setErrors(prev => ({ ...prev, [name]: '' }));
    }
  };

  const validate = () => {
    const newErrors = {};
    
    if (!formData.title.trim()) {
      newErrors.title = 'Title is required';
    }
    
    if (!formData.description.trim()) {
      newErrors.description = 'Description is required';
    }
    
    if (!formData.status_id) {
      newErrors.status_id = 'Status is required';
    }
    
    setErrors(newErrors);
    return Object.keys(newErrors).length === 0;
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    
    if (!validate()) {
      return;
    }
    
    console.log('Form submitted with data:', formData);
    
    setLoading(true);
    try {
      const issueData = {
        title: formData.title,
        description: formData.description,
        project_id: projectId,
        issue_status: formData.status_id,
        severity: formData.severity || 'medium',
        priority: formData.priority || 'medium',
        pages: formData.page_id ? [formData.page_id] : [],
        wcag_criteria: formData.wcag_criteria || '',
        issue_type: formData.issue_type || '',
        environments: formData.environments || '',
      };

      console.log('Sending to API:', issueData);

      if (isEditMode) {
        await updateIssue(issue.id, issueData);
      } else {
        await createIssue(issueData);
      }

      alert('Issue saved successfully!');
      onClose();
    } catch (error) {
      console.error('Failed to save issue:', error);
      alert('Failed to save issue: ' + error.message);
    } finally {
      setLoading(false);
    }
  };

  if (!isOpen) return null;

  return (
    <div className="modal fade show d-block" style={{ backgroundColor: 'rgba(0,0,0,0.5)', zIndex: 1050 }} onClick={onClose}>
      <div className="modal-dialog modal-xl" onClick={(e) => e.stopPropagation()}>
        <div className="modal-content">
          <div className="modal-header bg-primary text-white">
            <h5 className="modal-title">
              <i className={`fas ${isEditMode ? 'fa-edit' : 'fa-plus'} me-2`}></i>
              {isEditMode ? 'Edit Issue' : 'Create New Issue'}
            </h5>
            <button type="button" className="btn-close btn-close-white" onClick={onClose}></button>
          </div>

          <form onSubmit={handleSubmit}>
            <div className="modal-body">
              <div className="row">
                {/* Title */}
                <div className="col-md-12 mb-3">
                  <label className="form-label">
                    Issue Title <span className="text-danger">*</span>
                  </label>
                  <input
                    type="text"
                    name="title"
                    className={`form-control ${errors.title ? 'is-invalid' : ''}`}
                    value={formData.title}
                    onChange={handleChange}
                    placeholder="Enter issue title"
                  />
                  {errors.title && (
                    <div className="invalid-feedback">{errors.title}</div>
                  )}
                </div>

                {/* Description */}
                <div className="col-md-12 mb-3">
                  <label className="form-label">
                    Description <span className="text-danger">*</span>
                  </label>
                  <textarea
                    name="description"
                    className={`form-control ${errors.description ? 'is-invalid' : ''}`}
                    rows="8"
                    value={formData.description}
                    onChange={handleChange}
                    placeholder="Enter issue description..."
                  />
                  {errors.description && (
                    <div className="invalid-feedback">{errors.description}</div>
                  )}
                  <small className="text-muted">
                    You can use HTML tags for formatting if needed
                  </small>
                </div>

                {/* Status */}
                <div className="col-md-6 mb-3">
                  <label className="form-label">
                    Status <span className="text-danger">*</span>
                  </label>
                  <select
                    name="status_id"
                    className={`form-select ${errors.status_id ? 'is-invalid' : ''}`}
                    value={formData.status_id}
                    onChange={handleChange}
                  >
                    <option value="">Select Status</option>
                    {issueStatuses.map(status => (
                      <option key={status.id} value={status.id}>
                        {status.status_name}
                      </option>
                    ))}
                  </select>
                  {errors.status_id && (
                    <div className="invalid-feedback">{errors.status_id}</div>
                  )}
                </div>

                {/* Severity */}
                <div className="col-md-6 mb-3">
                  <label className="form-label">Severity</label>
                  <select 
                    name="severity"
                    className="form-select"
                    value={formData.severity}
                    onChange={handleChange}
                  >
                    <option value="">Select Severity</option>
                    <option value="Critical">Critical</option>
                    <option value="High">High</option>
                    <option value="Medium">Medium</option>
                    <option value="Low">Low</option>
                  </select>
                </div>

                {/* Priority */}
                <div className="col-md-6 mb-3">
                  <label className="form-label">Priority</label>
                  <select 
                    name="priority"
                    className="form-select"
                    value={formData.priority}
                    onChange={handleChange}
                  >
                    <option value="">Select Priority</option>
                    <option value="Critical">Critical</option>
                    <option value="High">High</option>
                    <option value="Medium">Medium</option>
                    <option value="Low">Low</option>
                  </select>
                </div>

                {/* Dynamic Metadata Fields */}
                {metadataFields.map(field => (
                  <div key={field.field_name} className="col-md-6 mb-3">
                    <label className="form-label">{field.label}</label>
                    {field.field_type === 'select' ? (
                      <select 
                        name={field.field_name}
                        className="form-select"
                        value={formData[field.field_name] || ''}
                        onChange={handleChange}
                      >
                        <option value="">Select {field.label}</option>
                        {field.options?.map(option => (
                          <option key={option} value={option}>
                            {option}
                          </option>
                        ))}
                      </select>
                    ) : field.field_type === 'textarea' ? (
                      <textarea
                        name={field.field_name}
                        className="form-control"
                        rows="3"
                        value={formData[field.field_name] || ''}
                        onChange={handleChange}
                        placeholder={`Enter ${field.label}`}
                      />
                    ) : (
                      <input
                        type="text"
                        name={field.field_name}
                        className="form-control"
                        value={formData[field.field_name] || ''}
                        onChange={handleChange}
                        placeholder={`Enter ${field.label}`}
                      />
                    )}
                  </div>
                ))}
              </div>
            </div>

            <div className="modal-footer">
              <Button
                type="button"
                variant="secondary"
                onClick={onClose}
                disabled={loading}
              >
                Cancel
              </Button>
              <Button
                type="submit"
                variant="primary"
                icon={`fas ${isEditMode ? 'fa-save' : 'fa-plus'}`}
                disabled={loading}
              >
                {loading ? 'Saving...' : (isEditMode ? 'Update Issue' : 'Create Issue')}
              </Button>
            </div>
          </form>
        </div>
      </div>
    </div>
  );
};

export default IssueModal;
