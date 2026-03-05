import React, { useState, memo } from 'react';
import Badge from '../Common/Badge';
import Comments from '../Comments/Comments';
import IssueModal from '../IssueModal/IssueModal';
import useIssuesStore from '../../store/issuesStore';
import { 
  formatDateTime, 
  getSeverityColor, 
  getPriorityColor,
  parseArrayValue,
  stripHtml,
  truncate
} from '../../utils/formatters';

const IssueRow = memo(({ issue, isExpanded, onToggle, projectId }) => {
  const [showEditModal, setShowEditModal] = useState(false);
  const { deleteIssue } = useIssuesStore();
  const severity = parseArrayValue(issue.severity);
  const priority = parseArrayValue(issue.priority);
  
  const handleDelete = async () => {
    if (window.confirm('Are you sure you want to delete this issue?')) {
      try {
        await deleteIssue(issue.id);
      } catch (error) {
        alert('Failed to delete issue. Please try again.');
      }
    }
  };
  
  return (
    <>
      <tr>
        <td>
          <i 
            className={`fas fa-chevron-right ${isExpanded ? 'rotate-90' : ''}`}
            style={{ cursor: 'pointer', transition: 'transform 0.2s' }}
            onClick={onToggle}
          ></i>
        </td>
        <td>
          <Badge variant="primary" className="px-2 py-1">
            {issue.issue_key || 'N/A'}
          </Badge>
        </td>
        <td>
          <strong>{issue.title || 'Untitled'}</strong>
        </td>
        <td>
          <Badge variant="info">
            {issue.status || 'Open'}
          </Badge>
        </td>
        <td>
          <Badge variant={getPriorityColor(priority)}>
            {priority}
          </Badge>
        </td>
        <td>
          <Badge variant={getSeverityColor(severity)}>
            {severity}
          </Badge>
        </td>
        <td>
          <small>{issue.reporter_name || 'N/A'}</small>
        </td>
        <td>
          <small className="text-muted">
            {formatDateTime(issue.updated_at)}
          </small>
        </td>
        <td>
          <button 
            className="btn btn-sm btn-outline-primary me-1"
            title="Edit"
            onClick={() => setShowEditModal(true)}
          >
            <i className="fas fa-edit"></i>
          </button>
          <button 
            className="btn btn-sm btn-outline-danger"
            title="Delete"
            onClick={handleDelete}
          >
            <i className="fas fa-trash"></i>
          </button>
        </td>
      </tr>
      {isExpanded && (
        <tr className="bg-light">
          <td colSpan="9">
            <div className="p-3">
              <h6>Issue Details</h6>
              <div 
                className="issue-content"
                dangerouslySetInnerHTML={{ 
                  __html: issue.description || '<p class="text-muted">No description</p>' 
                }}
              />
              {issue.pages && issue.pages.length > 0 && (
                <div className="mt-3">
                  <strong>Pages:</strong> {issue.pages.join(', ')}
                </div>
              )}
              
              {/* Comments Section */}
              <Comments issueId={issue.id} />
            </div>
          </td>
        </tr>
      )}
      
      {/* Edit Modal */}
      {showEditModal && (
        <IssueModal
          isOpen={showEditModal}
          onClose={() => setShowEditModal(false)}
          issue={issue}
          projectId={projectId}
        />
      )}
    </>
  );
});

IssueRow.displayName = 'IssueRow';

export default IssueRow;
