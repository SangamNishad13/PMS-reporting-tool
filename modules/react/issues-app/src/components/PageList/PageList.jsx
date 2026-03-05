import { useState, useEffect } from 'react';
import useIssuesStore from '../../store/issuesStore';
import Badge from '../Common/Badge';
import './PageList.css';

const PageList = ({ projectId }) => {
  const { projectPages, selectedPageId, setSelectedPageId, fetchProjectPages } = useIssuesStore();
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (projectId) {
      loadPages();
    }
  }, [projectId]);

  const loadPages = async () => {
    setLoading(true);
    try {
      await fetchProjectPages(projectId);
    } catch (error) {
      console.error('Failed to load pages:', error);
    } finally {
      setLoading(false);
    }
  };

  if (loading) {
    return (
      <div className="text-center py-3">
        <div className="spinner-border spinner-border-sm" role="status">
          <span className="visually-hidden">Loading...</span>
        </div>
      </div>
    );
  }

  return (
    <div className="page-list">
      <div className="page-list-header">
        <h6 className="mb-0">
          <i className="fas fa-file-alt me-2"></i>
          Pages
        </h6>
      </div>
      
      <div className="page-list-body">
        {/* All Pages Option */}
        <div
          className={`page-item ${selectedPageId === null ? 'active' : ''}`}
          onClick={() => setSelectedPageId(null)}
        >
          <div className="page-item-content">
            <i className="fas fa-list me-2"></i>
            <span>All Pages</span>
          </div>
          <Badge variant="secondary" size="sm">
            {projectPages.reduce((sum, page) => sum + (page.issue_count || 0), 0)}
          </Badge>
        </div>

        {/* Individual Pages */}
        {projectPages.length === 0 ? (
          <div className="text-center text-muted py-3">
            <small>No pages found</small>
          </div>
        ) : (
          projectPages.map(page => (
            <div
              key={page.id}
              className={`page-item ${selectedPageId === page.id ? 'active' : ''}`}
              onClick={() => setSelectedPageId(page.id)}
            >
              <div className="page-item-content">
                <div>
                  <div className="page-number">{page.page_number}</div>
                  <div className="page-name">{page.page_name}</div>
                </div>
              </div>
              <Badge variant="primary" size="sm">
                {page.issue_count || 0}
              </Badge>
            </div>
          ))
        )}
      </div>
    </div>
  );
};

export default PageList;
