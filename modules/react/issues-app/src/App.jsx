import { useState, useEffect } from 'react'
import IssueTable from './components/IssueTable/IssueTable'
import Button from './components/Common/Button'
import './App.css'

function App() {
  const [config, setConfig] = useState(null);
  const [activeView, setActiveView] = useState('all'); // 'all', 'pages', 'common'

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
    <div className="container-fluid mt-4">
      <div className="row">
        <div className="col-12">
          {/* Header */}
          <div className="card shadow-sm mb-4">
            <div className="card-header bg-primary text-white">
              <div className="d-flex justify-content-between align-items-center">
                <div>
                  <h4 className="mb-0">
                    <i className="fas fa-bug me-2"></i>
                    {config.projectTitle} - Issues
                  </h4>
                  <small>React Version | User: {config.userName} ({config.userRole})</small>
                </div>
                <Button 
                  variant="light" 
                  icon="fas fa-plus"
                  onClick={() => alert('Create issue modal - Coming soon!')}
                >
                  New Issue
                </Button>
              </div>
            </div>
          </div>

          {/* View Tabs */}
          <div className="card shadow-sm mb-4">
            <div className="card-body">
              <ul className="nav nav-tabs">
                <li className="nav-item">
                  <button 
                    className={`nav-link ${activeView === 'all' ? 'active' : ''}`}
                    onClick={() => setActiveView('all')}
                  >
                    <i className="fas fa-list me-2"></i>
                    All Issues
                  </button>
                </li>
                <li className="nav-item">
                  <button 
                    className={`nav-link ${activeView === 'pages' ? 'active' : ''}`}
                    onClick={() => setActiveView('pages')}
                  >
                    <i className="fas fa-file-alt me-2"></i>
                    By Pages
                  </button>
                </li>
                <li className="nav-item">
                  <button 
                    className={`nav-link ${activeView === 'common' ? 'active' : ''}`}
                    onClick={() => setActiveView('common')}
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
              {activeView === 'all' && (
                <IssueTable projectId={config.projectId} />
              )}
              {activeView === 'pages' && (
                <div className="alert alert-info">
                  <i className="fas fa-info-circle me-2"></i>
                  Issues by Pages view - Coming soon!
                </div>
              )}
              {activeView === 'common' && (
                <div className="alert alert-info">
                  <i className="fas fa-info-circle me-2"></i>
                  Common Issues view - Coming soon!
                </div>
              )}
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}

export default App
