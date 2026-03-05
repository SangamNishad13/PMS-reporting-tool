import { useState, useEffect } from 'react'
import IssueTable from './components/IssueTable/IssueTable'
import IssueModal from './components/IssueModal/IssueModal'
import PageList from './components/PageList/PageList'
import Button from './components/Common/Button'
import useIssuesStore from './store/issuesStore'
import './App.css'

function App() {
  const [config, setConfig] = useState(null);
  const [showCreateModal, setShowCreateModal] = useState(false);
  const { viewMode, setViewMode } = useIssuesStore();

  useEffect(() => {
    // Get config from PHP
    if (window.APP_CONFIG) {
      setConfig(window.APP_CONFIG);
    }
  }, []);

  if (!config) {
    return (
      <div className="container mt-5">
        <div className="text-center">
          <div className="spinner-border text-primary" role="status">
            <span className="visually-hidden">Loading...</span>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="react-app-container">
      <div className="container-fluid">
        {/* Breadcrumb */}
        <nav aria-label="breadcrumb" className="mt-3">
          <ol className="breadcrumb">
            <li className="breadcrumb-item">
              <a href={`${config.baseUrl}/index.php`}>
                <i className="fas fa-home"></i> Home
              </a>
            </li>
            <li className="breadcrumb-item">
              <a href={`${config.baseUrl}/modules/projects/list.php`}>Projects</a>
            </li>
            <li className="breadcrumb-item">
              <a href={`${config.baseUrl}/modules/projects/view.php?id=${config.projectId}`}>
                {config.projectTitle}
              </a>
            </li>
            <li className="breadcrumb-item active" aria-current="page">
              Issues (React)
            </li>
          </ol>
        </nav>

        {/* Page Header */}
        <div className="d-flex justify-content-between align-items-center mb-3">
          <div>
            <h4 className="mb-1">
              <i className="fas fa-bug me-2 text-danger"></i>
              {config.projectTitle} - Issues
            </h4>
            <p className="text-muted mb-0">
              <small>React Version | User: {config.userName} ({config.userRole})</small>
            </p>
          </div>
          <Button 
            variant="primary" 
            icon="fas fa-plus"
            onClick={() => setShowCreateModal(true)}
          >
            New Issue
          </Button>
        </div>

        {/* View Tabs */}
        <div className="card shadow-sm mb-3">
          <div className="card-body py-2">
            <ul className="nav nav-tabs border-0">
              <li className="nav-item">
                <button 
                  className={`nav-link ${viewMode === 'all' ? 'active' : ''}`}
                  onClick={() => setViewMode('all')}
                >
                  <i className="fas fa-list me-2"></i>
                  All Issues
                </button>
              </li>
              <li className="nav-item">
                <button 
                  className={`nav-link ${viewMode === 'pages' ? 'active' : ''}`}
                  onClick={() => setViewMode('pages')}
                >
                  <i className="fas fa-file-alt me-2"></i>
                  By Pages
                </button>
              </li>
              <li className="nav-item">
                <button 
                  className={`nav-link ${viewMode === 'common' ? 'active' : ''}`}
                  onClick={() => setViewMode('common')}
                >
                  <i className="fas fa-layer-group me-2"></i>
                  Common Issues
                </button>
              </li>
            </ul>
          </div>
        </div>

        {/* Content */}
        <div className="card shadow-sm">
          <div className="card-body">
            {viewMode === 'all' && (
              <IssueTable projectId={config.projectId} />
            )}
            {viewMode === 'pages' && (
              <div className="row">
                <div className="col-md-3">
                  <PageList projectId={config.projectId} />
                </div>
                <div className="col-md-9">
                  <IssueTable projectId={config.projectId} />
                </div>
              </div>
            )}
            {viewMode === 'common' && (
              <IssueTable projectId={config.projectId} />
            )}
          </div>
        </div>
      </div>
      
      {/* Create Issue Modal */}
      {showCreateModal && (
        <IssueModal
          isOpen={showCreateModal}
          onClose={() => setShowCreateModal(false)}
          projectId={config.projectId}
        />
      )}
    </div>
  )
}

export default App
