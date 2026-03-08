import { useState, useEffect, useRef, memo } from 'react';
import './IssueTable.css';

const IssueTable = memo(({ 
  issues = [], 
  selectedIds = [], 
  onSelectAll, 
  onSelectIssue, 
  onEdit, 
  onDelete,
  loading = false 
}) => {
  const [expandedRows, setExpandedRows] = useState([]);
  const [expandedPages, setExpandedPages] = useState({});
  const [expandedUrls, setExpandedUrls] = useState({});
  const [expandedComments, setExpandedComments] = useState({}); // Track which issue's comments are expanded
  const [issueComments, setIssueComments] = useState({});
  const [loadingComments, setLoadingComments] = useState({});
  const [imageModal, setImageModal] = useState(null); // { src, alt }
  const imageModalRef = useRef(null);
  
  const config = window.ProjectConfig || {};
  const userRole = config.userRole || '';
  const isClient = userRole === 'client';
  
  // Add click handler for images in issue details
  useEffect(() => {
    const handleImageClick = (e) => {
      if (e.target.tagName === 'IMG' && e.target.closest('.issue-details-content')) {
        e.preventDefault();
        setImageModal({
          src: e.target.src,
          alt: e.target.alt || 'Issue Screenshot'
        });
      }
    };
    
    document.addEventListener('click', handleImageClick);
    return () => document.removeEventListener('click', handleImageClick);
  }, []);
  
  // Focus trap for image modal
  useEffect(() => {
    if (!imageModal) return;
    
    const handleKeyDown = (e) => {
      // Close on ESC
      if (e.key === 'Escape') {
        setImageModal(null);
        return;
      }
      
      // Trap focus - prevent Tab from leaving modal
      if (e.key === 'Tab') {
        e.preventDefault();
        const closeButton = imageModalRef.current?.querySelector('.image-modal-close');
        if (closeButton) {
          closeButton.focus();
        }
      }
    };
    
    // Focus close button when modal opens
    setTimeout(() => {
      const closeButton = imageModalRef.current?.querySelector('.image-modal-close');
      if (closeButton) {
        closeButton.focus();
      }
    }, 100);
    
    document.addEventListener('keydown', handleKeyDown);
    return () => document.removeEventListener('keydown', handleKeyDown);
  }, [imageModal]);

  const toggleExpand = async (issueId) => {
    const isExpanding = !expandedRows.includes(issueId);
    
    setExpandedRows(prev => 
      prev.includes(issueId) 
        ? prev.filter(id => id !== issueId)
        : [...prev, issueId]
    );

    // Load comments when expanding
    if (isExpanding && !issueComments[issueId]) {
      await loadComments(issueId);
    }
  };

  const loadComments = async (issueId) => {
    const issue = issues.find(i => i.id === issueId);
    if (!issue) return;

    setLoadingComments(prev => ({ ...prev, [issueId]: true }));
    
    try {
      const response = await window.issuesApi.getComments(issueId, issue.project_id);
      setIssueComments(prev => ({
        ...prev,
        [issueId]: response.comments || []
      }));
    } catch (error) {
      setIssueComments(prev => ({
        ...prev,
        [issueId]: []
      }));
    } finally {
      setLoadingComments(prev => ({ ...prev, [issueId]: false }));
    }
  };

  const toggleExpandPages = (issueId) => {
    setExpandedPages(prev => ({
      ...prev,
      [issueId]: !prev[issueId]
    }));
  };

  const toggleExpandUrls = (issueId) => {
    setExpandedUrls(prev => ({
      ...prev,
      [issueId]: !prev[issueId]
    }));
  };

  const toggleExpandComments = (issueId) => {
    setExpandedComments(prev => ({
      ...prev,
      [issueId]: !prev[issueId]
    }));
  };

  const handleSelectAll = (e) => {
    if (e.target.checked) {
      onSelectAll(issues.map(issue => issue.id));
    } else {
      onSelectAll([]);
    }
  };

  const getStatusBadge = (issue) => {
    // Handle both status_id and issue_status_id
    const statusId = issue.status_id || issue.issue_status_id;
    const status = (config.issueStatuses || []).find(s => parseInt(s.id) === parseInt(statusId));
    if (!status) return <span className="badge bg-secondary">Unknown</span>;
    
    return (
      <span 
        className="badge" 
        style={{ 
          backgroundColor: status.color || '#6c757d',
          color: '#fff'
        }}
      >
        {status.name}
      </span>
    );
  };

  const getQaStatusBadge = (issue) => {
    // qa_status is an array from API
    const qaStatusArray = issue.qa_status || [];
    if (!Array.isArray(qaStatusArray) || qaStatusArray.length === 0) {
      return <span className="badge bg-secondary">-</span>;
    }
    
    // Get first QA status
    const qaStatusKey = qaStatusArray[0];
    const qaStatus = (config.qaStatuses || []).find(s => s.status_key === qaStatusKey);
    if (!qaStatus) return <span className="badge bg-secondary">-</span>;
    
    // Calculate contrasting text color based on background
    const getContrastColor = (hexColor) => {
      if (!hexColor) return '#000';
      
      // Remove # if present and handle short hex codes
      let hex = hexColor.replace('#', '').trim();
      
      // Handle 3-digit hex codes (e.g., #fff -> #ffffff)
      if (hex.length === 3) {
        hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
      }
      
      // Ensure we have a valid 6-digit hex
      if (hex.length !== 6) return '#000';
      
      // Convert to RGB
      const r = parseInt(hex.substring(0, 2), 16);
      const g = parseInt(hex.substring(2, 4), 16);
      const b = parseInt(hex.substring(4, 6), 16);
      
      // Check if parsing was successful
      if (isNaN(r) || isNaN(g) || isNaN(b)) return '#000';
      
      // Calculate relative luminance using WCAG formula
      const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
      
      // Return black for light backgrounds (luminance > 0.5), white for dark
      return luminance > 0.5 ? '#000000' : '#ffffff';
    };
    
    const bgColor = qaStatus.badge_color || '#6c757d';
    const textColor = getContrastColor(bgColor);
    
    return (
      <span 
        className="badge" 
        style={{ 
          backgroundColor: bgColor,
          color: textColor,
          border: (bgColor.toLowerCase() === '#ffffff' || bgColor.toLowerCase() === '#fff') ? '1px solid #dee2e6' : 'none',
          display: 'inline-block',
          padding: '4px 10px',
          fontSize: '0.75rem',
          fontWeight: '500',
          borderRadius: '10px',
          whiteSpace: 'nowrap'
        }}
      >
        {qaStatus.status_label}
        {qaStatusArray.length > 1 && <span className="ms-1">+{qaStatusArray.length - 1}</span>}
      </span>
    );
  };

  const getPageNames = (issue) => {
    // Handle both page_ids and pages fields
    let pageIds = issue.page_ids || issue.pages || [];
    
    // Convert to array if string
    if (typeof pageIds === 'string') {
      pageIds = pageIds.split(',').map(id => id.trim()).filter(Boolean);
    }
    
    // Ensure it's an array
    if (!Array.isArray(pageIds)) {
      pageIds = [pageIds];
    }
    
    if (pageIds.length === 0) return '-';
    
    // Convert pageIds to numbers for comparison
    const pageIdNumbers = pageIds.map(id => parseInt(id));
    
    const pages = (config.projectPages || [])
      .filter(p => pageIdNumbers.includes(parseInt(p.id)))
      .map(p => p.page_name);
    
    if (pages.length === 0) return '-';
    if (pages.length === 1) return pages[0];
    
    return (
      <div>
        {pages[0]}
        {pages.length > 1 && (
          <span className="badge bg-light text-dark ms-1">+{pages.length - 1}</span>
        )}
      </div>
    );
  };

  const getReporterNames = (issue) => {
    // Handle both reporter_ids and reporters fields
    let reporterIds = issue.reporter_ids || issue.reporters || [];
    
    // Convert to array if string
    if (typeof reporterIds === 'string') {
      reporterIds = reporterIds.split(',').map(id => id.trim()).filter(Boolean);
    }
    
    // Ensure it's an array
    if (!Array.isArray(reporterIds)) {
      reporterIds = [reporterIds];
    }
    
    if (reporterIds.length === 0) return '-';
    
    // Convert reporterIds to numbers for comparison
    const reporterIdNumbers = reporterIds.map(id => parseInt(id));
    
    const reporters = (config.projectUsers || [])
      .filter(u => reporterIdNumbers.includes(parseInt(u.id)))
      .map(u => u.full_name);
    
    if (reporters.length === 0) return '-';
    if (reporters.length === 1) return reporters[0];
    
    return (
      <div>
        {reporters[0]}
        {reporters.length > 1 && (
          <span className="badge bg-light text-dark ms-1">+{reporters.length - 1}</span>
        )}
      </div>
    );
  };

  const parseMetadata = (issue) => {
    const metadata = {};
    const metadataFields = window.issueMetadataFields || [];
    
    metadataFields.forEach(field => {
      const key = field.field_key;
      const label = field.field_label;
      
      if (issue[key]) {
        const value = issue[key];
        // Convert array to comma-separated string for display
        metadata[label] = Array.isArray(value) ? value.join(', ') : value;
      }
    });
    
    return metadata;
  };

  if (loading) {
    return (
      <div className="text-center py-5">
        <div className="spinner-border" role="status">
          <span className="visually-hidden">Loading...</span>
        </div>
      </div>
    );
  }

  if (issues.length === 0) {
    return (
      <div className="text-center py-5 text-muted">
        <i className="fas fa-inbox fa-3x mb-3 opacity-25"></i>
        <div>No issues found for this page.</div>
        <div className="small mt-1">Click "Add Issue" to create one.</div>
      </div>
    );
  }

  return (
    <div className="table-responsive">
      <table className="table table-sm table-hover align-middle mb-0">
        <thead className="table-light">
          <tr>
            {!isClient && (
              <th style={{ width: '30px' }}>
                <input
                  type="checkbox"
                  checked={selectedIds.length === issues.length && issues.length > 0}
                  onChange={handleSelectAll}
                />
              </th>
            )}
            <th style={{ width: '40px' }}></th>
            <th style={{ width: '120px', whiteSpace: 'nowrap' }}>Issue Key</th>
            <th>Issue Title</th>
            <th style={{ width: '100px' }}>Severity</th>
            <th style={{ width: '100px' }}>Priority</th>
            <th style={{ width: '120px' }}>Status</th>
            {!isClient && (
              <>
                <th style={{ width: '120px' }}>QA Status</th>
                <th style={{ width: '120px' }}>Reporter</th>
                <th style={{ width: '120px' }}>QA Name</th>
                <th style={{ width: '100px' }}>Client Ready</th>
              </>
            )}
            <th style={{ width: '100px' }}>Pages</th>
            {!isClient && <th style={{ width: '120px' }}>Actions</th>}
          </tr>
        </thead>
        <tbody>
          {issues.map(issue => {
            const isExpanded = expandedRows.includes(issue.id);
            const metadata = parseMetadata(issue);
            
            return (
              <>
                <tr 
                  key={issue.id}
                  onClick={(e) => {
                    // Don't expand if clicking on checkbox, buttons, or links
                    if (
                      e.target.tagName === 'INPUT' ||
                      e.target.tagName === 'BUTTON' ||
                      e.target.tagName === 'A' ||
                      e.target.closest('button') ||
                      e.target.closest('a')
                    ) {
                      return;
                    }
                    toggleExpand(issue.id);
                  }}
                  style={{ cursor: 'pointer' }}
                >
                  {!isClient && (
                    <td onClick={(e) => e.stopPropagation()}>
                      <input
                        type="checkbox"
                        checked={selectedIds.includes(issue.id)}
                        onChange={() => onSelectIssue(issue.id)}
                      />
                    </td>
                  )}
                  <td onClick={(e) => e.stopPropagation()}>
                    <button
                      className="btn btn-sm btn-link p-0"
                      onClick={() => toggleExpand(issue.id)}
                      title={isExpanded ? 'Collapse' : 'Expand'}
                    >
                      <i className={`fas fa-chevron-${isExpanded ? 'down' : 'right'}`}></i>
                    </button>
                  </td>
                  <td style={{ whiteSpace: 'nowrap' }}>
                    {issue.issue_key || '-'}
                  </td>
                  <td>
                    <div className="issue-title-cell">
                      {issue.common_issue_title || issue.title || issue.issue_title || 'Untitled'}
                    </div>
                  </td>
                  <td>{issue.severity || '-'}</td>
                  <td>{issue.priority || '-'}</td>
                  <td>{getStatusBadge(issue)}</td>
                  {!isClient && (
                    <>
                      <td>{getQaStatusBadge(issue)}</td>
                      <td>{getReporterNames(issue)}</td>
                      <td>{issue.qa_name || '-'}</td>
                      <td>
                        {issue.client_ready === 1 || issue.client_ready === '1' ? (
                          <span className="badge bg-success">
                            <i className="fas fa-check"></i> Yes
                          </span>
                        ) : (
                          <span className="badge bg-secondary">No</span>
                        )}
                      </td>
                    </>
                  )}
                  <td>{getPageNames(issue)}</td>
                  {!isClient && (
                    <td onClick={(e) => e.stopPropagation()}>
                      <div className="btn-group btn-group-sm">
                        <button
                          className="btn btn-outline-primary"
                          onClick={(e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            onEdit(issue);
                          }}
                          title="Edit"
                        >
                          <i className="fas fa-edit"></i>
                        </button>
                        <button
                          className="btn btn-outline-danger"
                          onClick={(e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            onDelete([issue.id]);
                          }}
                          title="Delete"
                        >
                          <i className="fas fa-trash"></i>
                        </button>
                      </div>
                    </td>
                  )}
                </tr>
                
                {/* Expanded Row */}
                {isExpanded && (
                  <tr className="expanded-row">
                    <td colSpan={isClient ? 8 : 13}>
                      <div className="expanded-content">
                        <div className="row g-3">
                          {/* Issue Details - Left Side (always 8 columns) */}
                          <div className="col-lg-8 col-md-7">
                            <h6 className="fw-bold mb-2">Issue Details</h6>
                            <div 
                              className="issue-details-content"
                              dangerouslySetInnerHTML={{ __html: issue.description || issue.issue_details || '<p class="text-muted">No details provided.</p>' }}
                            />
                            
                            {/* Comments Section */}
                            <div className="mt-4 pt-3 border-top">
                              <div className="d-flex justify-content-between align-items-center mb-3">
                                <h6 className="fw-bold mb-0">
                                  <i className="fas fa-comments me-2"></i>
                                  Comments
                                  {issueComments[issue.id] && issueComments[issue.id].filter(c => !c.deleted_at).length > 0 && (
                                    <span className="badge bg-secondary ms-2">{issueComments[issue.id].filter(c => !c.deleted_at).length}</span>
                                  )}
                                </h6>
                                {issueComments[issue.id] && issueComments[issue.id].filter(c => !c.deleted_at).length > 0 && (
                                  <button
                                    className="btn btn-sm btn-outline-secondary"
                                    onClick={() => toggleExpandComments(issue.id)}
                                  >
                                    <i className={`fas fa-chevron-${expandedComments[issue.id] ? 'up' : 'down'} me-1`}></i>
                                    {expandedComments[issue.id] ? 'Hide' : 'Show'} Messages
                                  </button>
                                )}
                              </div>
                              
                              {loadingComments[issue.id] ? (
                                <div className="text-center py-3">
                                  <div className="spinner-border spinner-border-sm" role="status">
                                    <span className="visually-hidden">Loading comments...</span>
                                  </div>
                                  <div className="small text-muted mt-2">Loading comments...</div>
                                </div>
                              ) : issueComments[issue.id] && issueComments[issue.id].filter(c => !c.deleted_at).length > 0 ? (
                                expandedComments[issue.id] && (
                                  <div className="comments-list-table">
                                    {issueComments[issue.id]
                                      .filter(c => !c.deleted_at)
                                      .map((comment, idx) => (
                                      <div key={idx} className="comment-box-table">
                                        {comment.reply_preview && (
                                          <div className="reply-preview-table">
                                            <i className="fas fa-reply me-1"></i>
                                            <strong>{comment.reply_preview.user_name}:</strong>
                                            <span dangerouslySetInnerHTML={{ __html: comment.reply_preview.text?.substring(0, 50) + '...' || '' }} />
                                          </div>
                                        )}
                                        <div className="d-flex justify-content-between align-items-start mb-2">
                                          <div className="d-flex align-items-center gap-2">
                                            <strong className="comment-author-table">{comment.user_name || 'Unknown'}</strong>
                                            <span className="comment-time-table">{comment.created_at}</span>
                                            {comment.comment_type === 'regression' && (
                                              <span className="badge bg-warning text-dark">Regression</span>
                                            )}
                                            {comment.edited_at && (
                                              <span className="text-muted small">(edited)</span>
                                            )}
                                          </div>
                                        </div>
                                        <div
                                          className="comment-body-table"
                                          dangerouslySetInnerHTML={{ __html: comment.comment_html || comment.comment || '' }}
                                        />
                                      </div>
                                    ))}
                                  </div>
                                )
                              ) : (
                                <div className="text-center py-4 text-muted">
                                  <i className="fas fa-comment-slash fa-2x mb-2 opacity-25"></i>
                                  <p className="mb-0">No comments yet.</p>
                                  <small>Click Edit to add comments.</small>
                                </div>
                              )}
                            </div>
                          </div>
                          
                          {/* Metadata - Right Side (always 4 columns) */}
                          <div className="col-lg-4 col-md-5">
                            <h6 className="fw-bold mb-2">Metadata</h6>
                            <div className="metadata-list small">
                              {/* Pages - Expandable */}
                              {(() => {
                                let pageIds = issue.page_ids || issue.pages || [];
                                if (typeof pageIds === 'string') {
                                  pageIds = pageIds.split(',').map(id => id.trim()).filter(Boolean);
                                }
                                if (!Array.isArray(pageIds)) {
                                  pageIds = [pageIds];
                                }
                                
                                if (pageIds.length > 0) {
                                  const pageIdNumbers = pageIds.map(id => parseInt(id));
                                  const pages = (config.projectPages || [])
                                    .filter(p => pageIdNumbers.includes(parseInt(p.id)))
                                    .map(p => p.page_name);
                                  
                                  if (pages.length > 0) {
                                    const isExpanded = expandedPages[issue.id];
                                    return (
                                      <div className="mb-2">
                                        <div className="d-flex align-items-center gap-2 mb-1">
                                          <strong>Pages ({pages.length}):</strong>
                                          {pages.length > 1 && (
                                            <button
                                              className="btn btn-xs btn-link p-0 text-decoration-none"
                                              onClick={() => toggleExpandPages(issue.id)}
                                              style={{ fontSize: '0.85rem', lineHeight: 1 }}
                                              title={isExpanded ? 'Collapse' : 'Expand'}
                                            >
                                              <i className={`fas fa-chevron-${isExpanded ? 'up' : 'down'}`}></i>
                                            </button>
                                          )}
                                        </div>
                                        {pages.length === 1 ? (
                                          <ul className="mb-0 ps-3"><li>{pages[0]}</li></ul>
                                        ) : isExpanded ? (
                                          <ul className="mb-0 ps-3">
                                            {pages.map((pageName, idx) => (
                                              <li key={idx}>{pageName}</li>
                                            ))}
                                          </ul>
                                        ) : (
                                          <div>
                                            {pages[0]}
                                            <span className="badge bg-light text-dark ms-1">+{pages.length - 1} more</span>
                                          </div>
                                        )}
                                      </div>
                                    );
                                  }
                                }
                                return null;
                              })()}
                              
                              {/* Grouped URLs - Expandable */}
                              {(() => {
                                let groupedUrls = issue.grouped_urls || [];
                                if (typeof groupedUrls === 'string') {
                                  groupedUrls = groupedUrls.split(',').map(url => url.trim()).filter(Boolean);
                                }
                                if (!Array.isArray(groupedUrls)) {
                                  groupedUrls = [groupedUrls];
                                }
                                
                                if (groupedUrls.length > 0) {
                                  const isExpanded = expandedUrls[issue.id];
                                  return (
                                    <div className="mb-2">
                                      <div className="d-flex align-items-center gap-2 mb-1">
                                        <strong>Grouped URLs ({groupedUrls.length}):</strong>
                                        {groupedUrls.length > 1 && (
                                          <button
                                            className="btn btn-xs btn-link p-0 text-decoration-none"
                                            onClick={() => toggleExpandUrls(issue.id)}
                                            style={{ fontSize: '0.85rem', lineHeight: 1 }}
                                            title={isExpanded ? 'Collapse' : 'Expand'}
                                          >
                                            <i className={`fas fa-chevron-${isExpanded ? 'up' : 'down'}`}></i>
                                          </button>
                                        )}
                                      </div>
                                      <div className="small">
                                        {groupedUrls.length === 1 ? (
                                          <ul className="mb-0 ps-3">
                                            <li>
                                              <a href={groupedUrls[0]} target="_blank" rel="noopener noreferrer" className="text-decoration-none">
                                                {groupedUrls[0]}
                                              </a>
                                            </li>
                                          </ul>
                                        ) : isExpanded ? (
                                          <div style={{ maxHeight: '200px', overflowY: 'auto' }}>
                                            <ul className="mb-0 ps-3">
                                              {groupedUrls.map((url, idx) => (
                                                <li key={idx} style={{ marginBottom: '6px' }}>
                                                  <a href={url} target="_blank" rel="noopener noreferrer" className="text-decoration-none">
                                                    {url}
                                                  </a>
                                                </li>
                                              ))}
                                            </ul>
                                          </div>
                                        ) : (
                                          <div>
                                            <a href={groupedUrls[0]} target="_blank" rel="noopener noreferrer" className="text-decoration-none">
                                              {groupedUrls[0]}
                                            </a>
                                            <span className="badge bg-light text-dark ms-1">+{groupedUrls.length - 1} more</span>
                                          </div>
                                        )}
                                      </div>
                                    </div>
                                  );
                                }
                                return null;
                              })()}
                              
                              {/* Other Metadata */}
                              {Object.keys(metadata).length === 0 ? (
                                <p className="text-muted">No additional metadata.</p>
                              ) : (
                                Object.entries(metadata).map(([key, value]) => (
                                  <div key={key} className="mb-2">
                                    <strong>{key}:</strong><br />
                                    {value || '-'}
                                  </div>
                                ))
                              )}
                            </div>
                            
                            {/* Comments Count */}
                            {issue.comments_count > 0 && (
                              <div className="mt-3">
                                <span className="badge bg-info">
                                  <i className="fas fa-comments me-1"></i>
                                  {issue.comments_count} Comments
                                </span>
                              </div>
                            )}
                          </div>
                        </div>
                      </div>
                    </td>
                  </tr>
                )}
              </>
            );
          })}
        </tbody>
      </table>
      
      {/* Image Modal */}
      {imageModal && (
        <div 
          className="image-modal-overlay" 
          onClick={() => setImageModal(null)}
          ref={imageModalRef}
          role="dialog"
          aria-modal="true"
          aria-label="Image preview"
        >
          <div className="image-modal-content" onClick={(e) => e.stopPropagation()}>
            <button 
              className="image-modal-close" 
              onClick={() => setImageModal(null)}
              aria-label="Close image preview"
            >
              <i className="fas fa-times"></i>
            </button>
            <img src={imageModal.src} alt={imageModal.alt} />
            {imageModal.alt && (
              <div className="image-modal-caption">
                {imageModal.alt}
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
});

export default IssueTable;
