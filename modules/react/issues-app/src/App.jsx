import { useEffect, useState, useMemo } from 'react';
import useIssuesStore from './store/issuesStore';
import IssueModal from './components/IssueModal/IssueModal';
import IssueTable from './components/IssueTable/IssueTable';
import ConfirmModal from './components/ConfirmModal/ConfirmModal';
import './App.css';

function App() {
  const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);
  const [deleteTarget, setDeleteTarget] = useState(null); // { issueIds: [...] }
  
  const {
    issues,
    loading,
    selectedIssueIds,
    isModalOpen,
    modalMode,
    currentIssue,
    projectId,
    pageId,
    activeTab,
    setProjectContext,
    loadIssues,
    openAddModal,
    openEditModal,
    closeModal,
    saveIssue,
    deleteIssues,
    markClientReady,
    toggleSelectIssue,
    selectAllIssues,
    clearSelection,
  } = useIssuesStore();

  const config = window.ProjectConfig || {};
  const userRole = config.userRole || '';
  const isClient = userRole === 'client';

  useEffect(() => {
    // Get project and page context from window
    const projId = config.projectId;
    const pgId = window.location.pathname.includes('issues_page_detail') 
      ? new URLSearchParams(window.location.search).get('page_id')
      : null;
    
    // Detect page type
    const isCommonPage = window.location.pathname.includes('issues_common');
    const isAllPage = window.location.pathname.includes('issues_all');

    if (projId) {
      setProjectContext(projId, pgId);
      
      if (isCommonPage) {
        // Load only common issues (issues with common_title)
        loadIssues(projId, null, { onlyCommon: true });
      } else if (isAllPage) {
        // Load all issues
        loadIssues(projId, null, { allIssues: true });
      } else if (pgId) {
        // Load issues for specific page
        loadIssues(projId, pgId);
      }
    }
  }, []);
  
  // Keyboard shortcuts
  useEffect(() => {
    let currentEditIndex = -1;
    
    const handleKeyDown = (e) => {
      // Alt+A - Open Add Issue modal
      if (e.altKey && e.key === 'a') {
        e.preventDefault();
        if (!isClient) {
          openAddModal();
        }
      }
      
      // Alt+E - Navigate to next edit button (forward)
      if (e.altKey && e.key === 'e') {
        e.preventDefault();
        const editButtons = document.querySelectorAll('.btn-outline-primary[title="Edit"]');
        if (editButtons.length > 0) {
          currentEditIndex = (currentEditIndex + 1) % editButtons.length;
          editButtons[currentEditIndex].focus();
        }
      }
      
      // Alt+R - Navigate to previous edit button (reverse)
      if (e.altKey && e.key === 'r') {
        e.preventDefault();
        const editButtons = document.querySelectorAll('.btn-outline-primary[title="Edit"]');
        if (editButtons.length > 0) {
          currentEditIndex = currentEditIndex <= 0 ? editButtons.length - 1 : currentEditIndex - 1;
          editButtons[currentEditIndex].focus();
        }
      }
    };
    
    document.addEventListener('keydown', handleKeyDown);
    return () => document.removeEventListener('keydown', handleKeyDown);
  }, [isClient, openAddModal]);
  
  // Detect page type for button text
  const isCommonPage = window.location.pathname.includes('issues_common');
  const isAllPage = window.location.pathname.includes('issues_all');
  const addButtonText = isCommonPage ? 'Add Common Issue' : 'Add Issue';

  const handleEdit = (issue) => {
    openEditModal(issue.id);
  };

  const handleDelete = async (issueIds) => {
    // Show confirmation modal
    setDeleteTarget({ issueIds });
    setShowDeleteConfirm(true);
  };
  
  const confirmDelete = async () => {
    if (!deleteTarget) return;
    
    const result = await deleteIssues(deleteTarget.issueIds);
    if (result.success) {
      alert('Issues deleted successfully');
    } else {
      alert('Failed to delete issues: ' + result.error);
    }
    
    setDeleteTarget(null);
  };

  const handleMarkClientReady = async () => {
    if (selectedIssueIds.length === 0) {
      alert('Please select issues to mark as client ready');
      return;
    }

    const result = await markClientReady(selectedIssueIds);
    if (result.success) {
      alert('Issues marked as client ready');
    } else {
      alert('Failed to mark issues: ' + result.error);
    }
  };

  const handleDeleteSelected = async () => {
    if (selectedIssueIds.length === 0) {
      alert('Please select issues to delete');
      return;
    }

    await handleDelete(selectedIssueIds);
  };

  const finalIssues = useMemo(() => {
    return issues.filter(issue => {
      // Show all issues - don't filter by category
      return true;
    });
  }, [issues]);

  return (
    <div className="issues-app">
      {/* Action Bar */}
      {!isClient && (
        <div className="d-flex justify-content-between align-items-center px-3 py-2 border-bottom bg-light">
          <div>
            <button 
              id="add-issue-btn"
              className="btn btn-sm btn-primary" 
              onClick={openAddModal}
            >
              <i className="fas fa-plus"></i> {addButtonText}
            </button>
          </div>
          <div>
            <button 
              className="btn btn-sm btn-outline-success me-1" 
              onClick={handleMarkClientReady}
              disabled={selectedIssueIds.length === 0}
            >
              <i className="fas fa-check"></i> Mark Client Ready
            </button>
            <button 
              className="btn btn-sm btn-outline-secondary" 
              onClick={handleDeleteSelected}
              disabled={selectedIssueIds.length === 0}
            >
              Delete Selected
            </button>
          </div>
        </div>
      )}

      {/* Issues Table */}
      <IssueTable
        issues={finalIssues}
        selectedIds={selectedIssueIds}
        onSelectAll={selectAllIssues}
        onSelectIssue={toggleSelectIssue}
        onEdit={handleEdit}
        onDelete={handleDelete}
        loading={loading}
      />

      {/* Issue Modal */}
      <IssueModal
        isOpen={isModalOpen}
        onClose={closeModal}
        issue={currentIssue}
        mode={modalMode}
        projectId={projectId}
        pageId={pageId}
      />
      
      {/* Delete Confirmation Modal */}
      <ConfirmModal
        isOpen={showDeleteConfirm}
        onClose={() => setShowDeleteConfirm(false)}
        onConfirm={confirmDelete}
        title="Delete Issue(s)"
        message={`Are you sure you want to delete ${deleteTarget?.issueIds?.length || 0} issue(s)? This action cannot be undone.`}
        confirmText="Delete"
        cancelText="Cancel"
        confirmButtonClass="btn-danger"
        icon="fa-trash"
      />
    </div>
  );
}

export default App;
