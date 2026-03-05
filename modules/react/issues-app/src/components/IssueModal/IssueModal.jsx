import { useState, useEffect } from 'react';
import { useForm } from 'react-hook-form';
import ReactQuill from 'react-quill';
import 'react-quill/dist/quill.snow.css';
import useIssuesStore from '../../store/issuesStore';
import Button from '../Common/Button';
import './IssueModal.css';

const IssueModal = ({ isOpen, onClose, issue = null, projectId }) => {
  const { register, handleSubmit, setValue, watch, reset, formState: { errors } } = useForm();
  const { createIssue, updateIssue, issueStatuses, metadataFields, fetchIssueStatuses, fetchMetadataFields } = useIssuesStore();
  
  const [description, setDescription] = useState('');
  const [loading, setLoading] = useState(false);

  const isEditMode = !!issue;

  useEffect(() => {
    if (isOpen) {
      fetchIssueStatuses();
      fetchMetadataFields('accessibility'); // Default project type
      
      if (issue) {
        // Populate form with issue data
        reset({
          title: issue.title || '',
          status_id: issue.status_id || '',
          page_id: issue.page_id || '',
          severity: issue.severity || '',
          wcag_criteria: issue.wcag_criteria || '',
          issue_type: issue.issue_type || '',
          environments: issue.environments || '',
        });
        setDescription(issue.description || '');
      } else {
        // Reset form for new issue
        reset({
          title: '',
          status_id: '',
          page_id: '',
          severity: '',
          wcag_criteria: '',
          issue_type: '',
          environments: '',
        });
        setDescription('');
      }
    }
  }, [isOpen, issue, reset, fetchIssueStatuses, fetchMetadataFields]);

  const onSubmit = async (data) => {
    console.log('Form submitted with data:', data);
    console.log('Description:', description);
    
    setLoading(true);
    try {
      const issueData = {
        title: data.title,
        description,
        project_id: projectId,
        issue_status: data.status_id, // API expects issue_status
        severity: data.severity || 'medium',
        priority: data.priority || 'medium',
        pages: data.page_id ? [data.page_id] : [],
        // Add other metadata fields
        wcag_criteria: data.wcag_criteria || '',
        issue_type: data.issue_type || '',
        environments: data.environments || '',
      };

      console.log('Sending to API:', issueData);

      if (isEditMode) {
        await updateIssue(issue.id, issueData);
      } else {
        await createIssue(issueData);
      }

      alert('Issue saved successfully!');
      onClose();
      reset();
      setDescription('');
    } catch (error) {
      console.error('Failed to save issue:', error);
      alert('Failed to save issue: ' + error.message);
    } finally {
      setLoading(false);
    }
  };

  const quillModules = {
    toolbar: [
      [{ 'header': [1, 2, 3, false] }],
      ['bold', 'italic', 'underline', 'strike'],
      [{ 'list': 'ordered'}, { 'list': 'bullet' }],
      [{ 'color': [] }, { 'background': [] }],
      ['link', 'image'],
      ['clean']
    ],
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

          <form onSubmit={handleSubmit(onSubmit)}>
            <div className="modal-body">
              <div className="row">
                {/* Title */}
                <div className="col-md-12 mb-3">
                  <label className="form-label">
                    Issue Title <span className="text-danger">*</span>
                  </label>
                  <input
                    type="text"
                    className={`form-control ${errors.title ? 'is-invalid' : ''}`}
                    {...register('title', { required: 'Title is required' })}
                    placeholder="Enter issue title"
                  />
                  {errors.title && (
                    <div className="invalid-feedback">{errors.title.message}</div>
                  )}
                </div>

                {/* Description */}
                <div className="col-md-12 mb-3">
                  <label className="form-label">
                    Description <span className="text-danger">*</span>
                  </label>
                  <ReactQuill
                    theme="snow"
                    value={description}
                    onChange={setDescription}
                    modules={quillModules}
                    placeholder="Enter issue description..."
                    style={{ height: '200px', marginBottom: '50px' }}
                  />
                </div>

                {/* Status */}
                <div className="col-md-6 mb-3">
                  <label className="form-label">
                    Status <span className="text-danger">*</span>
                  </label>
                  <select
                    className={`form-select ${errors.status_id ? 'is-invalid' : ''}`}
                    {...register('status_id', { required: 'Status is required' })}
                  >
                    <option value="">Select Status</option>
                    {issueStatuses.map(status => (
                      <option key={status.id} value={status.id}>
                        {status.status_name}
                      </option>
                    ))}
                  </select>
                  {errors.status_id && (
                    <div className="invalid-feedback">{errors.status_id.message}</div>
                  )}
                </div>

                {/* Severity */}
                <div className="col-md-6 mb-3">
                  <label className="form-label">Severity</label>
                  <select className="form-select" {...register('severity')}>
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
                  <select className="form-select" {...register('priority')}>
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
                      <select className="form-select" {...register(field.field_name)}>
                        <option value="">Select {field.label}</option>
                        {field.options?.map(option => (
                          <option key={option} value={option}>
                            {option}
                          </option>
                        ))}
                      </select>
                    ) : field.field_type === 'textarea' ? (
                      <textarea
                        className="form-control"
                        rows="3"
                        {...register(field.field_name)}
                        placeholder={`Enter ${field.label}`}
                      />
                    ) : (
                      <input
                        type="text"
                        className="form-control"
                        {...register(field.field_name)}
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
