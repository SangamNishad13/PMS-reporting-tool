import { useState, useEffect, useRef } from 'react';
import Select from 'react-select';
import CreatableSelect from 'react-select/creatable';
import SummernoteEditor from '../SummernoteEditor/SummernoteEditor';
import ConfirmModal from '../ConfirmModal/ConfirmModal';
import ComboboxAutocomplete from '../ComboboxAutocomplete/ComboboxAutocomplete';
import { issuesApi } from '../../services/issuesApi';
import './IssueModal.css';

// Simple word-level diff function for inline changes
const generateInlineDiff = (oldText, newText) => {
  try {
    if (!oldText && !newText) return <span className="text-muted">No changes</span>;
    if (!oldText) return <span className="diff-added">{newText}</span>;
    if (!newText) return <span className="diff-removed">{oldText}</span>;
    
    // Get user name from ID
    const getUserName = (userId) => {
      const config = window.ProjectConfig || {};
      const users = config.projectUsers || [];
      const user = users.find(u => String(u.id) === String(userId));
      return user ? user.full_name : `User ${userId}`;
    };
    
    // Check if it's JSON (reporter_qa_status_map)
    const isJson = (str) => {
      if (!str || typeof str !== 'string') return false;
      return str.trim().startsWith('{') || str.trim().startsWith('[');
    };
    
    // Parse and format JSON for better display
    const formatJson = (str) => {
      try {
        const parsed = JSON.parse(str);
        // Convert to readable format
        if (typeof parsed === 'object' && parsed !== null) {
          const entries = [];
          for (const [key, value] of Object.entries(parsed)) {
            const userName = getUserName(key);
            if (Array.isArray(value)) {
              if (value.length > 0) {
                entries.push(`${userName}: ${value.join(', ')}`);
              } else {
                entries.push(`${userName}: (none)`);
              }
            } else {
              entries.push(`${userName}: ${value}`);
            }
          }
          return entries.join('; ');
        }
        return str;
      } catch {
        return str;
      }
    };
    
    // If both are JSON, format them
    if (isJson(oldText) && isJson(newText)) {
      const oldFormatted = formatJson(oldText);
      const newFormatted = formatJson(newText);
      
      if (oldFormatted === newFormatted) {
        return <span>{oldFormatted}</span>;
      }
      
      return (
        <div>
          <div><span className="diff-removed">{oldFormatted}</span></div>
          <div><span className="diff-added">{newFormatted}</span></div>
        </div>
      );
    }
    
    // Strip HTML tags for comparison
    const stripHtml = (html) => {
      if (!html) return '';
      const tmp = document.createElement('div');
      tmp.innerHTML = html;
      return tmp.textContent || tmp.innerText || '';
    };
    
    const oldPlain = stripHtml(oldText);
    const newPlain = stripHtml(newText);
    
    // If texts are identical, show no change
    if (oldPlain === newPlain) {
      return <span>{oldPlain}</span>;
    }
    
    // Simple word-level diff
    const oldWords = oldPlain.split(/(\s+)/);
    const newWords = newPlain.split(/(\s+)/);
    
    const result = [];
    let i = 0, j = 0;
    
    while (i < oldWords.length || j < newWords.length) {
      if (i >= oldWords.length) {
        // Only new words left
        result.push(<span key={`add-${j}`} className="diff-added">{newWords[j]}</span>);
        j++;
      } else if (j >= newWords.length) {
        // Only old words left
        result.push(<span key={`del-${i}`} className="diff-removed">{oldWords[i]}</span>);
        i++;
      } else if (oldWords[i] === newWords[j]) {
        // Words match
        result.push(<span key={`same-${i}-${j}`}>{oldWords[i]}</span>);
        i++;
        j++;
      } else {
        // Words differ - check if it's an insertion or deletion
        const oldInNew = newWords.slice(j).indexOf(oldWords[i]);
        const newInOld = oldWords.slice(i).indexOf(newWords[j]);
        
        if (oldInNew !== -1 && (newInOld === -1 || oldInNew < newInOld)) {
          // Insertion
          result.push(<span key={`add-${j}`} className="diff-added">{newWords[j]}</span>);
          j++;
        } else if (newInOld !== -1) {
          // Deletion
          result.push(<span key={`del-${i}`} className="diff-removed">{oldWords[i]}</span>);
          i++;
        } else {
          // Replacement
          result.push(<span key={`del-${i}`} className="diff-removed">{oldWords[i]}</span>);
          result.push(<span key={`add-${j}`} className="diff-added">{newWords[j]}</span>);
          i++;
          j++;
        }
      }
    }
    
    return <div className="inline-diff">{result}</div>;
  } catch (error) {
    // Fallback to simple display
    return (
      <div>
        {oldText && <div className="diff-removed">{oldText}</div>}
        {newText && <div className="diff-added">{newText}</div>}
      </div>
    );
  }
};

const IssueModal = ({ isOpen, onClose, issue, mode, projectId, pageId }) => {
  const [formData, setFormData] = useState({
    issue_title: '',
    issue_details: '',
    issue_status_id: '',
    page_ids: [],
    reporter_ids: [],
    assignee_id: null,
    metadata: {},
    client_ready: false,
  });
  
  const [commonIssueTitle, setCommonIssueTitle] = useState(''); // For multiple pages
  
  const [activeTab, setActiveTab] = useState('chat');
  const [comments, setComments] = useState([]);
  const [history, setHistory] = useState([]);
  const [visitHistory, setVisitHistory] = useState([]);
  const [newComment, setNewComment] = useState('');
  const [commentType, setCommentType] = useState('normal');
  const [mentionedUsers, setMentionedUsers] = useState([]);
  const [replyToComment, setReplyToComment] = useState(null); // Track which comment is being replied to
  const [editingComment, setEditingComment] = useState(null); // Track which comment is being edited
  const [editCommentText, setEditCommentText] = useState(''); // Text for editing comment
  const [commentHistories, setCommentHistories] = useState({}); // Store comment histories by comment ID
  const [expandedCommentHistory, setExpandedCommentHistory] = useState(null); // Track which comment's history is expanded
  const [loading, setLoading] = useState(false);
  const [latestHistoryId, setLatestHistoryId] = useState(0); // Track latest history ID for conflict detection
  const [activeUsers, setActiveUsers] = useState([]); // Track active users viewing this issue
  const [showConflictWarning, setShowConflictWarning] = useState(false);
  const [conflictData, setConflictData] = useState(null);
  const [saving, setSaving] = useState(false);
  const [presets, setPresets] = useState([]);
  const [titleSuggestions, setTitleSuggestions] = useState([]);
  const [selectedPresetTitle, setSelectedPresetTitle] = useState('');
  const [groupedUrls, setGroupedUrls] = useState([]);
  const [availableGroupedUrls, setAvailableGroupedUrls] = useState([]);
  const [showUrlModal, setShowUrlModal] = useState(false);
  const [showGroupedUrlsPreview, setShowGroupedUrlsPreview] = useState(false);
  const [reporterQaStatusMap, setReporterQaStatusMap] = useState({});
  const [hasUnsavedChanges, setHasUnsavedChanges] = useState(false);
  const [initialFormData, setInitialFormData] = useState(null);
  const [showUnsavedWarning, setShowUnsavedWarning] = useState(false);
  const [showDeleteCommentConfirm, setShowDeleteCommentConfirm] = useState(false);
  const [deleteCommentTarget, setDeleteCommentTarget] = useState(null);
  const [metadataExpanded, setMetadataExpanded] = useState(true); // Metadata section expanded by default
  const [isDiscarding, setIsDiscarding] = useState(false); // Flag to prevent save during discard
  const lastFocusedElementRef = useRef(null); // Store last focused element before warning modal
  const triggerElementRef = useRef(null); // Store element that triggered the modal

  // Get grouped URLs from config
  const allGroupedUrls = window.ProjectConfig?.groupedUrls || [];
  const projectPages = window.ProjectConfig?.projectPages || [];
  const qaStatuses = window.ProjectConfig?.qaStatuses || [];

  // Get config from window
  const config = window.ProjectConfig || {};
  const metadataFields = window.issueMetadataFields || [];
  const userRole = config.userRole || '';
  const isClient = userRole === 'client';
  const projectType = config.projectType || 'web';

  useEffect(() => {
    if (isOpen) {
      // Prevent body scroll when modal is open
      document.body.classList.add('modal-open');
      
      // Store the element that triggered the modal (currently focused element)
      triggerElementRef.current = document.activeElement;
      
      // Reset unsaved changes flag when modal opens
      setHasUnsavedChanges(false);
      setShowUnsavedWarning(false);
      
      if (issue && mode === 'edit') {
        loadIssueData();
        // Start tracking active user
        startActiveUserTracking();
        // Start chat polling for live updates
        startChatPolling();
      } else if (mode === 'add') {
        resetForm();
      }
      // Always load presets when modal opens
      loadPresets();
    } else {
      // Re-enable body scroll when modal closes
      document.body.classList.remove('modal-open');
      
      // Modal is closing - restore focus to trigger element
      if (triggerElementRef.current && triggerElementRef.current.focus) {
        setTimeout(() => {
          triggerElementRef.current.focus();
        }, 100);
      }
      // Stop tracking when modal closes
      stopActiveUserTracking();
      // Stop chat polling when modal closes
      stopChatPolling();
      
      // Reset form data when modal closes to prevent stale data
      resetForm();
    }
  }, [isOpen, issue, mode]);
  
  // Active user tracking
  const activeUserIntervalRef = useRef(null);
  const chatPollingIntervalRef = useRef(null);
  
  const startActiveUserTracking = () => {
    if (!issue?.id) return;
    
    // Track immediately
    trackUser();
    
    // Track every 3 seconds (faster polling for better real-time feel)
    activeUserIntervalRef.current = setInterval(() => {
      trackUser();
      fetchActiveUsers();
    }, 3000);
    
    // Fetch active users immediately
    fetchActiveUsers();
  };
  
  const stopActiveUserTracking = () => {
    if (activeUserIntervalRef.current) {
      clearInterval(activeUserIntervalRef.current);
      activeUserIntervalRef.current = null;
    }
    
    // Immediately notify backend that user is leaving
    if (issue?.id && projectId) {
      issuesApi.leaveIssue(issue.id, projectId).catch(() => {
        // Silently fail - not critical
      });
    }
  };
  
  // Chat polling for live updates
  const startChatPolling = () => {
    if (!issue?.id) return;
    
    // Poll every 5 seconds
    chatPollingIntervalRef.current = setInterval(async () => {
      try {
        const commentsResponse = await issuesApi.getComments(issue.id, projectId);
        const newComments = commentsResponse.comments || [];
        
        // Only update if comments changed
        if (JSON.stringify(newComments) !== JSON.stringify(comments)) {
          setComments(newComments);
        }
      } catch (error) {
        // Silently fail - not critical
      }
    }, 5000);
  };
  
  const stopChatPolling = () => {
    if (chatPollingIntervalRef.current) {
      clearInterval(chatPollingIntervalRef.current);
      chatPollingIntervalRef.current = null;
    }
  };
  
  const trackUser = async () => {
    if (!issue?.id) return;
    try {
      await issuesApi.trackActiveUser(issue.id, projectId);
    } catch (error) {
      // Silently fail - not critical
    }
  };
  
  const fetchActiveUsers = async () => {
    if (!issue?.id) return;
    try {
      const response = await issuesApi.getActiveUsers(issue.id, projectId);
      setActiveUsers(response.active_users || []);
    } catch (error) {
      // Silently fail - not critical
    }
  };
  
  // Track unsaved changes
  useEffect(() => {
    if (!initialFormData) return;
    
    // Add a small delay to avoid false positives during initial load
    const timeoutId = setTimeout(() => {
      // Deep comparison function that handles arrays and objects properly
      const deepEqual = (obj1, obj2) => {
        if (obj1 === obj2) return true;
        if (obj1 == null || obj2 == null) return false;
        if (typeof obj1 !== 'object' || typeof obj2 !== 'object') return obj1 === obj2;
        
        // Handle arrays
        if (Array.isArray(obj1) && Array.isArray(obj2)) {
          if (obj1.length !== obj2.length) return false;
          // Create new sorted arrays without modifying originals
          const sorted1 = [...obj1].map(v => typeof v === 'number' ? v : String(v)).sort();
          const sorted2 = [...obj2].map(v => typeof v === 'number' ? v : String(v)).sort();
          return sorted1.every((val, idx) => val === sorted2[idx]);
        }
        
        // Handle objects - ignore commonIssueTitle key as it's tracked separately
        const keys1 = Object.keys(obj1).filter(k => k !== 'commonIssueTitle').sort();
        const keys2 = Object.keys(obj2).filter(k => k !== 'commonIssueTitle').sort();
        
        // Get all unique keys from both objects (excluding commonIssueTitle)
        const allKeys = [...new Set([...keys1, ...keys2])];
        
        return allKeys.every(key => deepEqual(obj1[key], obj2[key]));
      };
      
      const hasChanges = !deepEqual(formData, initialFormData) ||
        commonIssueTitle !== (initialFormData.commonIssueTitle || '');
      
      setHasUnsavedChanges(hasChanges);
    }, 300); // Increased delay to 300ms for stability
    
    return () => clearTimeout(timeoutId);
  }, [formData, commonIssueTitle, initialFormData]);
  
  // Prevent page reload/close when there are unsaved changes
  useEffect(() => {
    const handleBeforeUnload = (e) => {
      if (hasUnsavedChanges && isOpen) {
        e.preventDefault();
        e.returnValue = ''; // Chrome requires returnValue to be set
        return ''; // Some browsers show this message
      }
    };
    
    window.addEventListener('beforeunload', handleBeforeUnload);
    return () => window.removeEventListener('beforeunload', handleBeforeUnload);
  }, [hasUnsavedChanges, isOpen]);
  
  // Handle ESC key and keyboard shortcuts
  useEffect(() => {
    if (!isOpen) return;
    
    const handleKeyDown = (e) => {
      // Handle Alt+S to save
      if (e.altKey && e.key === 's') {
        e.preventDefault();
        if (!showUnsavedWarning && !saving) {
          handleSave();
        }
      }
      
      // Handle Alt+C to focus comment editor (only in edit mode)
      if (e.altKey && e.key === 'c' && mode === 'edit' && issue?.id && !isClient) {
        e.preventDefault();
        // Switch to chat tab first
        if (activeTab !== 'chat') {
          setActiveTab('chat');
        }
        // Focus the comment Summernote editor (not the issue details editor)
        setTimeout(() => {
          // Look for the editor specifically in the chat tab content
          const chatTabContent = document.querySelector('.tab-content');
          if (chatTabContent) {
            // Find all note-editable elements
            const editables = chatTabContent.querySelectorAll('.note-editable');
            // The comment editor is the second one (first is issue details, second is comment)
            // Or we can look for the one that's currently visible in chat tab
            let commentEditor = null;
            editables.forEach(editable => {
              // Check if this editor is in the visible chat section
              const parent = editable.closest('.tab-pane');
              if (!parent || parent.querySelector('.comments-list')) {
                commentEditor = editable;
              }
            });
            
            if (commentEditor) {
              commentEditor.focus();
            } else if (editables.length > 1) {
              // Fallback: use the last editor (comment editor)
              editables[editables.length - 1].focus();
            }
          }
        }, 150);
      }
      
      // Handle ESC key - only if unsaved warning is not showing
      if (e.key === 'Escape' && !showUnsavedWarning) {
        handleClose();
      }
    };
    
    document.addEventListener('keydown', handleKeyDown);
    return () => document.removeEventListener('keydown', handleKeyDown);
  }, [isOpen, hasUnsavedChanges, showUnsavedWarning, saving, mode, issue, isClient, activeTab]);
  
  // Focus trap for accessibility
  useEffect(() => {
    if (!isOpen) return;
    
    const modalElement = document.querySelector('.modal-dialog');
    if (!modalElement) return;
    
    // Get all focusable elements
    const getFocusableElements = () => {
      return modalElement.querySelectorAll(
        'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
      );
    };
    
    const handleTabKey = (e) => {
      if (e.key !== 'Tab') return;
      
      const focusableElements = getFocusableElements();
      const firstElement = focusableElements[0];
      const lastElement = focusableElements[focusableElements.length - 1];
      
      if (e.shiftKey) {
        // Shift + Tab
        if (document.activeElement === firstElement) {
          e.preventDefault();
          lastElement.focus();
        }
      } else {
        // Tab
        if (document.activeElement === lastElement) {
          e.preventDefault();
          firstElement.focus();
        }
      }
    };
    
    // Focus first element when modal opens
    setTimeout(() => {
      const focusableElements = getFocusableElements();
      if (focusableElements.length > 0) {
        focusableElements[0].focus();
      }
    }, 100);
    
    document.addEventListener('keydown', handleTabKey);
    return () => document.removeEventListener('keydown', handleTabKey);
  }, [isOpen]);

  // Update grouped URLs when pages change
  useEffect(() => {
    if (formData.page_ids.length > 0) {
      updateGroupedUrlsForPages(formData.page_ids);
    } else {
      setGroupedUrls([]);
      setAvailableGroupedUrls([]);
    }
  }, [formData.page_ids]);

  const loadPresets = async () => {
    try {
      const response = await issuesApi.getPresets(projectType);
      
      // API returns {success: true, data: [...]}
      const presetsData = response.data || response.presets || [];
      setPresets(presetsData);
    } catch (error) {
      // Silently fail - not critical
    }
  };

  const loadIssueTitles = async (query) => {
    if (!query || query.trim().length < 2) {
      setTitleSuggestions([]);
      return;
    }
    
    try {
      const response = await issuesApi.getIssueTitles(projectType, query);
      const titles = (response.titles || []).filter(t => t && typeof t === 'string');
      setTitleSuggestions(titles);
    } catch (error) {
      setTitleSuggestions([]);
    }
  };

  const handleTitleChange = (value) => {
    // ComboboxAutocomplete passes value directly, not event object
    setFormData(prev => ({ ...prev, issue_title: value }));
    
    // Check if this title matches a preset
    const preset = presets.find(p => p.title === value);
    if (preset) {
      setSelectedPresetTitle(value);
    } else {
      setSelectedPresetTitle('');
    }
    
    if (value.length >= 2) {
      loadIssueTitles(value);
    }
  };

  const selectTitleSuggestion = async (title) => {
    if (!title) return;
    
    setFormData(prev => {
      return { ...prev, issue_title: title };
    });
    
    // Check if preset exists for this title
    const preset = presets.find(p => p.title === title);
    if (preset) {
      setSelectedPresetTitle(title);
    } else {
      setSelectedPresetTitle('');
    }
  };

  const handleApplyPreset = () => {
    if (!selectedPresetTitle) {
      alert('No preset selected');
      return;
    }
    
    const preset = presets.find(p => p.title === selectedPresetTitle);
    
    if (preset) {
      applyPreset(preset);
      alert('Preset applied successfully!');
    } else {
      alert('Preset not found for title: ' + selectedPresetTitle);
    }
  };

  const applyPreset = (preset) => {
    const metadata = preset.metadata_json ? 
      (typeof preset.metadata_json === 'string' ? JSON.parse(preset.metadata_json) : preset.metadata_json) 
      : {};
    
    const newFormData = {
      ...formData,
      issue_title: preset.title || formData.issue_title,
      issue_details: preset.description_html || '',
      metadata: { ...formData.metadata, ...metadata },
    };
    
    setFormData(newFormData);
    
    // Force Summernote to update and focus
    setTimeout(() => {
      const $editor = window.jQuery('.note-editable');
      if ($editor.length > 0) {
        $editor.html(preset.description_html || '');
        // Focus the issue details editor (first editor)
        $editor.first().focus();
      }
    }, 100);
  };

  const updateGroupedUrlsForPages = (pageIds) => {
    const urls = [];
    const urlSet = new Set();

    pageIds.forEach(pageId => {
      // Find grouped URLs for this page
      const pageGroupedUrls = allGroupedUrls.filter(row => {
        const mappedPageId = row.mapped_page_id || row.unique_page_id;
        return String(mappedPageId) === String(pageId);
      });

      if (pageGroupedUrls.length > 0) {
        pageGroupedUrls.forEach(row => {
          const url = row.url || row.normalized_url;
          if (url && !urlSet.has(url)) {
            urlSet.add(url);
            urls.push(url);
          }
        });
      } else {
        // If no grouped URLs, add page's primary URL
        const page = projectPages.find(p => String(p.id) === String(pageId));
        if (page) {
          const url = page.url || page.canonical_url || page.unique_url || page.normalized_url || page.page_url;
          if (url && !urlSet.has(url)) {
            urlSet.add(url);
            urls.push(url);
          }
        }
      }
    });

    setAvailableGroupedUrls(urls);
    // Auto-select all available URLs
    setGroupedUrls(urls);
  };

  const loadIssueData = async () => {
    setLoading(true);
    try {
      // Fetch fresh data from API instead of using passed issue object
      const response = await issuesApi.getIssue(issue.id, projectId);
      const issueData = response.issue;
      
      if (!issueData) {
        alert('Failed to load issue data. Please try again.');
        setLoading(false);
        return;
      }
      
      // Collect metadata from issue object
      // The API returns metadata fields directly on the issue object
      let metadata = {};
      
      // Get all metadata field keys from window.issueMetadataFields
      const metadataFieldKeys = (window.issueMetadataFields || []).map(f => f.field_key);
      
      // Extract metadata values from issue object
      metadataFieldKeys.forEach(key => {
        if (issueData[key]) {
          // Metadata values come as arrays from API
          const value = issueData[key];
          if (Array.isArray(value)) {
            // Join array values with comma for display in CreatableSelect
            metadata[key] = value.join(', ');
          } else {
            metadata[key] = value;
          }
        }
      });

      // Parse page_ids and reporter_ids
      let pageIds = [];
      if (issueData.page_ids) {
        pageIds = Array.isArray(issueData.page_ids) 
          ? issueData.page_ids.map(id => parseInt(id)).filter(id => !isNaN(id))
          : String(issueData.page_ids).split(',').map(id => parseInt(id)).filter(id => !isNaN(id));
      } else if (issueData.pages) {
        pageIds = Array.isArray(issueData.pages) 
          ? issueData.pages.map(id => parseInt(id)).filter(id => !isNaN(id))
          : String(issueData.pages).split(',').map(id => parseInt(id)).filter(id => !isNaN(id));
      }

      let reporterIds = [];
      if (issueData.reporter_ids) {
        reporterIds = Array.isArray(issueData.reporter_ids) 
          ? issueData.reporter_ids.map(id => parseInt(id)).filter(id => !isNaN(id))
          : String(issueData.reporter_ids).split(',').map(id => parseInt(id)).filter(id => !isNaN(id));
      } else if (issueData.reporters) {
        reporterIds = Array.isArray(issueData.reporters) 
          ? issueData.reporters.map(id => parseInt(id)).filter(id => !isNaN(id))
          : String(issueData.reporters).split(',').map(id => parseInt(id)).filter(id => !isNaN(id));
      }

      // Use assignee_id directly from API response
      let assigneeId = issueData.assignee_id || null;
      
      setFormData({
        issue_title: issueData.title || issueData.issue_title || '',
        issue_details: issueData.description || issueData.issue_details || '',
        issue_status_id: issueData.status_id || issueData.issue_status_id || '',
        page_ids: pageIds,
        reporter_ids: reporterIds,
        assignee_id: assigneeId,
        metadata: metadata,
        client_ready: issueData.client_ready === 1 || issueData.client_ready === '1',
      });
      
      // Save initial state for change tracking
      setInitialFormData({
        issue_title: issueData.title || issueData.issue_title || '',
        issue_details: issueData.description || issueData.issue_details || '',
        issue_status_id: issueData.status_id || issueData.issue_status_id || '',
        page_ids: pageIds,
        reporter_ids: reporterIds,
        assignee_id: assigneeId,
        metadata: metadata,
        client_ready: issueData.client_ready === 1 || issueData.client_ready === '1',
        commonIssueTitle: issueData.common_issue_title || ''
      });
      
      // Load common issue title if available
      if (issueData.common_issue_title) {
        setCommonIssueTitle(issueData.common_issue_title);
      }

      // Load grouped URLs if available
      if (issueData.grouped_urls && Array.isArray(issueData.grouped_urls)) {
        setGroupedUrls(issueData.grouped_urls);
      }

      // Load reporter QA status map if available
      if (issueData.reporter_qa_status_map && typeof issueData.reporter_qa_status_map === 'object') {
        setReporterQaStatusMap(issueData.reporter_qa_status_map);
      }
      
      // Store latest history ID for conflict detection
      if (issueData.latest_history_id) {
        setLatestHistoryId(parseInt(issueData.latest_history_id));
      }

      // Load comments, history, visit history only if issue has ID
      if (issueData.id) {
        try {
          const [commentsResponse, historyResponse, visitHistoryResponse] = await Promise.all([
            issuesApi.getComments(issueData.id, projectId).catch(() => ({ comments: [] })),
            issuesApi.getHistory(issueData.id, projectId).catch(() => ({ history: [] })),
            issuesApi.getVisitHistory(issueData.id, projectId).catch(() => ({ history: [] })),
          ]);
          
          setComments(commentsResponse.comments || []);
          setHistory(historyResponse.history || []);
          setVisitHistory(visitHistoryResponse.history || []);
        } catch (error) {
          // Silently fail - not critical
        }
      }
    } catch (error) {
      alert('Error loading issue data: ' + error.message);
    } finally {
      setLoading(false);
    }
  };

  const resetForm = () => {
    const newFormData = {
      issue_title: '',
      issue_details: '',
      issue_status_id: config.issueStatuses?.[0]?.id || '',
      page_ids: pageId ? [pageId] : [],
      reporter_ids: [],
      assignee_id: null,
      metadata: {},
      client_ready: false,
    };
    
    setFormData(newFormData);
    setCommonIssueTitle('');
    setComments([]);
    setHistory([]);
    setVisitHistory([]);
    setNewComment('');
    setActiveTab('chat');
    setHasUnsavedChanges(false);
    
    // Save initial state for change tracking
    setInitialFormData({
      ...newFormData,
      commonIssueTitle: ''
    });
  };
  
  const handleClose = () => {
    if (hasUnsavedChanges) {
      // Store the currently focused element before showing warning
      lastFocusedElementRef.current = document.activeElement;
      setShowUnsavedWarning(true);
      return;
    }
    onClose();
  };
  
  const handleDiscardChanges = async () => {
    setIsDiscarding(true); // Set flag to prevent any save operations
    setShowUnsavedWarning(false);
    setHasUnsavedChanges(false);
    
    // Close modal first
    onClose();
    
    // Reload issues to get fresh data from database
    // This ensures the store has the latest data
    if (window.issuesStore) {
      const state = window.issuesStore.getState();
      await state.loadIssues(projectId, pageId);
    }
    
    setIsDiscarding(false); // Reset flag after reload
  };
  
  const handleKeepEditing = () => {
    setShowUnsavedWarning(false);
    // Restore focus to the last focused element
    setTimeout(() => {
      if (lastFocusedElementRef.current && lastFocusedElementRef.current.focus) {
        lastFocusedElementRef.current.focus();
      }
    }, 100);
  };

  const handleSave = async () => {
    if (!formData.issue_title.trim()) {
      alert('Please enter issue title');
      return;
    }

    // Check for edit conflicts in edit mode
    if (mode === 'edit' && issue?.id) {
      try {
        const response = await issuesApi.getIssue(issue.id, projectId);
        const currentHistoryId = parseInt(response.issue?.latest_history_id || 0);
        
        if (currentHistoryId > latestHistoryId) {
          // Conflict detected - someone else modified the issue
          setConflictData({
            currentData: response.issue,
            currentHistoryId: currentHistoryId
          });
          setShowConflictWarning(true);
          return;
        }
      } catch (error) {
        // Continue with save if conflict check fails
      }
    }

    await performSave();
  };
  
  const performSave = async () => {
    // Prevent save if discarding
    if (isDiscarding) {
      return;
    }
    
    // Close modal immediately for instant feedback
    setHasUnsavedChanges(false);
    setShowUnsavedWarning(false);
    onClose();
    
    // Run save operation in background without blocking UI
    (async () => {
      try {
        // Separate severity and priority from other metadata (they go in issues table)
        const { severity, priority, ...otherMetadata } = formData.metadata;
        
        // Convert ALL metadata (both predefined and custom) comma-separated strings to arrays
        const metadataForApi = {};
        Object.keys(otherMetadata).forEach(key => {
          const value = otherMetadata[key];
          
          // Handle empty values
          if (!value || (typeof value === 'string' && value.trim() === '')) {
            return; // Skip empty values
          }
          
          if (typeof value === 'string' && value.includes(',')) {
            // Split comma-separated string into array
            metadataForApi[key] = value.split(',').map(v => v.trim()).filter(Boolean);
          } else if (value) {
            // Single value or already an array
            metadataForApi[key] = Array.isArray(value) ? value : [value];
          }
        });

        const saveData = {
          project_id: projectId,
          page_id: pageId,
          title: formData.issue_title,
          description: formData.issue_details || '',
          issue_status_id: formData.issue_status_id || (config.issueStatuses?.[0]?.id || ''),
          pages: formData.page_ids.length > 0 ? formData.page_ids.join(',') : String(pageId),
          reporters: formData.reporter_ids.length > 0 ? formData.reporter_ids.join(',') : '',
          assignee_id: formData.assignee_id || '',
          grouped_urls: groupedUrls.length > 0 ? groupedUrls.join(',') : '',
          reporter_qa_status_map: JSON.stringify(reporterQaStatusMap),
          metadata: JSON.stringify(metadataForApi),
          client_ready: formData.client_ready ? '1' : '0',
        };
        
        // Add common issue title if multiple pages selected
        if (formData.page_ids.length > 1 && commonIssueTitle.trim()) {
          saveData.common_title = commonIssueTitle.trim();
        }

        // Add severity and priority as separate fields (they go in issues table, not metadata)
        if (severity) {
          // Extract first value if it's comma-separated
          saveData.severity = typeof severity === 'string' && severity.includes(',') 
            ? severity.split(',')[0].trim() 
            : severity;
        }
        
        if (priority) {
          // Extract first value if it's comma-separated
          saveData.priority = typeof priority === 'string' && priority.includes(',') 
            ? priority.split(',')[0].trim() 
            : priority;
        }

        // Save the issue in background
        let savedIssueId;
        if (mode === 'edit' && issue?.id) {
          await issuesApi.updateIssue(issue.id, saveData);
          savedIssueId = issue.id;
        } else {
          const response = await issuesApi.createIssue(saveData);
          savedIssueId = response.id;
        }
        
        // Update only the saved issue in the list (not full reload) - in background
        if (window.issuesStore && savedIssueId) {
          const state = window.issuesStore.getState();
          if (mode === 'edit') {
            await state.updateSingleIssue(savedIssueId, projectId);
          } else {
            await state.addSingleIssue(savedIssueId, projectId);
          }
        }
        
        // Show success message briefly
        const successMsg = document.createElement('div');
        successMsg.textContent = 'Issue saved successfully!';
        successMsg.style.cssText = 'position:fixed;top:20px;right:20px;background:#28a745;color:white;padding:12px 24px;border-radius:4px;z-index:99999;box-shadow:0 2px 8px rgba(0,0,0,0.2);';
        document.body.appendChild(successMsg);
        setTimeout(() => successMsg.remove(), 3000);
        
      } catch (error) {
        // Show error
        const errorMsg = document.createElement('div');
        errorMsg.textContent = 'Failed to save: ' + (error.response?.data?.error || error.message || 'Unknown error');
        errorMsg.style.cssText = 'position:fixed;top:20px;right:20px;background:#dc3545;color:white;padding:12px 24px;border-radius:4px;z-index:99999;box-shadow:0 2px 8px rgba(0,0,0,0.2);';
        document.body.appendChild(errorMsg);
        setTimeout(() => errorMsg.remove(), 5000);
      }
    })();
  };

  const handleAddComment = async () => {
    if (!newComment.trim() || !issue?.id) return;
    
    try {
      // Extract mentioned user IDs from the comment HTML
      const mentionIds = extractMentionedUserIds(newComment);
      
      await issuesApi.addComment(
        issue.id, 
        projectId, 
        newComment, 
        commentType, 
        null, 
        mentionIds,
        replyToComment?.id || null
      );
      setNewComment('');
      setMentionedUsers([]);
      setReplyToComment(null);
      
      const commentsResponse = await issuesApi.getComments(issue.id, projectId);
      setComments(commentsResponse.comments || []);
    } catch (error) {
      alert('Failed to add comment: ' + (error.response?.data?.error || error.message));
    }
  };
  
  const handleReplyToComment = (comment) => {
    setReplyToComment(comment);
    setEditingComment(null); // Cancel edit if replying
    // Focus the comment editor
    setTimeout(() => {
      const summernoteEditable = document.querySelector('.tab-content .note-editable');
      if (summernoteEditable) {
        summernoteEditable.focus();
      }
    }, 100);
  };
  
  const handleCancelReply = () => {
    setReplyToComment(null);
  };
  
  const handleEditComment = (comment) => {
    setEditingComment(comment);
    setEditCommentText(comment.comment_html || comment.comment || '');
    setReplyToComment(null); // Cancel reply if editing
  };
  
  const handleCancelEdit = () => {
    setEditingComment(null);
    setEditCommentText('');
  };
  
  const handleOverwriteConflict = async () => {
    setShowConflictWarning(false);
    setConflictData(null);
    await performSave();
  };
  
  const handleReloadConflict = async () => {
    setShowConflictWarning(false);
    setConflictData(null);
    await loadIssueData();
    alert('Issue data has been reloaded with the latest changes. Please review and make your changes again.');
  };
  
  const handleSaveEdit = async () => {
    if (!editCommentText.trim() || !editingComment?.id) return;
    
    try {
      await issuesApi.editComment(editingComment.id, issue.id, projectId, editCommentText);
      
      // Reload comments
      const commentsResponse = await issuesApi.getComments(issue.id, projectId);
      setComments(commentsResponse.comments || []);
      
      setEditingComment(null);
      setEditCommentText('');
    } catch (error) {
      alert('Failed to edit comment: ' + (error.response?.data?.error || error.message));
    }
  };
  
  const handleDeleteComment = async (comment) => {
    // Show confirmation modal
    setDeleteCommentTarget(comment);
    setShowDeleteCommentConfirm(true);
  };
  
  const confirmDeleteComment = async () => {
    if (!deleteCommentTarget) return;
    
    try {
      await issuesApi.deleteComment(deleteCommentTarget.id, issue.id, projectId);
      
      // Reload comments
      const commentsResponse = await issuesApi.getComments(issue.id, projectId);
      setComments(commentsResponse.comments || []);
    } catch (error) {
      alert('Failed to delete comment: ' + (error.response?.data?.error || error.message));
    }
    
    setDeleteCommentTarget(null);
  };
  
  const handleToggleCommentHistory = async (comment) => {
    // If already expanded, collapse it
    if (expandedCommentHistory === comment.id) {
      setExpandedCommentHistory(null);
      return;
    }
    
    // If not loaded yet, fetch history
    if (!commentHistories[comment.id]) {
      try {
        const response = await issuesApi.getCommentHistory(comment.id, issue.id, projectId);
        setCommentHistories(prev => ({
          ...prev,
          [comment.id]: response.history || []
        }));
      } catch (error) {
        alert('Failed to load comment history: ' + (error.response?.data?.error || error.message));
        return;
      }
    }
    
    // Expand this comment's history
    setExpandedCommentHistory(comment.id);
  };
  
  // Extract user IDs from mention spans in HTML
  const extractMentionedUserIds = (html) => {
    const mentionIds = [];
    const regex = /data-user-id="(\d+)"/g;
    let match;
    while ((match = regex.exec(html)) !== null) {
      const userId = parseInt(match[1]);
      if (userId && !mentionIds.includes(userId)) {
        mentionIds.push(userId);
      }
    }
    return mentionIds;
  };

  const handleMetadataChange = (fieldKey, value) => {
    setFormData(prev => ({
      ...prev,
      metadata: {
        ...prev.metadata,
        [fieldKey]: value,
      },
    }));
  };

  const handleImagePaste = async (e) => {
    const items = e.clipboardData?.items;
    if (!items) return;

    for (let i = 0; i < items.length; i++) {
      if (items[i].type.indexOf('image') !== -1) {
        e.preventDefault();
        const file = items[i].getAsFile();
        
        const altText = prompt('Enter alternative text for this image:');
        if (altText === null) return;

        try {
          const response = await issuesApi.uploadImage(file, projectId);
          if (response.success && response.url) {
            const imgHtml = `<img src="${response.url}" alt="${altText || ''}" />`;
            setFormData(prev => ({
              ...prev,
              issue_details: prev.issue_details + imgHtml,
            }));
          }
        } catch (error) {
          alert('Failed to upload image');
        }
      }
    }
  };

  const handleResetToTemplate = async () => {
    if (confirm('Reset issue details to default template? This will replace current content.')) {
      try {
        const response = await issuesApi.getDefaultTemplate(projectType);
        
        const sections = response.data?.sections || [];
        
        if (sections.length === 0) {
          alert('No default template found. Please configure default sections in admin panel.');
          return;
        }
        
        // Build HTML from sections with blank lines between
        let html = '';
        sections.forEach((section, index) => {
          if (section && typeof section === 'object') {
            const title = section.title || section.name || '';
            const content = section.content || section.description || '';
            
            if (title) {
              html += `<p><strong>[${title}]</strong></p>\n`;
              html += `<p>${content}</p>\n`;
              // Add blank line between sections (except after last section)
              if (index < sections.length - 1) {
                html += `<p><br></p>\n`;
              }
            }
          } else if (typeof section === 'string') {
            // If section is just a string (section name)
            html += `<p><strong>[${section}]</strong></p>\n`;
            html += `<p></p>\n`;
            // Add blank line between sections (except after last section)
            if (index < sections.length - 1) {
              html += `<p><br></p>\n`;
            }
          }
        });
        
        if (!html) {
          html = '<p>Default template loaded but no content available.</p>';
        }
        
        setFormData(prev => ({
          ...prev,
          issue_details: html
        }));
        
        // Force Summernote to update and focus
        setTimeout(() => {
          const $editor = window.jQuery('.note-editable');
          if ($editor.length > 0) {
            $editor.html(html);
            // Focus the issue details editor (first editor)
            $editor.first().focus();
          }
        }, 100);
        
        alert('Default template loaded successfully!');
      } catch (error) {
        alert('Failed to load default template: ' + (error.message || 'Unknown error'));
      }
    }
  };

  if (!isOpen) return null;

  const pageOptions = (config.projectPages || []).map(p => ({
    value: p.id,
    label: p.page_name,
  }));

  const reporterOptions = (config.projectUsers || []).map(u => ({
    value: parseInt(u.id),
    label: u.full_name,
  }));

  const statusOptions = (config.issueStatuses || []).map(s => ({
    value: s.id,
    label: s.name,
    color: s.color,
  }));

  return (
    <div 
      className="modal-overlay" 
      onClick={handleClose}
      role="dialog"
      aria-modal="true"
      aria-labelledby="issue-modal-title"
    >
      <div 
        className="modal-dialog modal-xl" 
        onClick={e => e.stopPropagation()}
        inert={showUnsavedWarning ? "" : undefined}
      >
        <div className="modal-content">
          <div className="modal-header">
            <div className="flex-grow-1">
              <h5 className="modal-title" id="issue-modal-title">
                <i className={`fas fa-${mode === 'edit' ? 'edit' : 'plus-circle'}`}></i>
                {mode === 'edit' ? 'Edit Issue' : 'Add New Issue'}
              </h5>
              <div className="small" style={{ color: 'rgba(255, 255, 255, 0.9)' }}>
                {mode === 'edit' ? 'Update issue information and track changes' : 'Create a new issue with details and metadata'}
              </div>
              
              {/* Active Users Indicator */}
              {mode === 'edit' && activeUsers.length > 0 && (
                <div className="active-users-indicator mt-2">
                  <div className="d-flex align-items-center gap-2">
                    <span className="small text-muted">
                      <i className="fas fa-users me-1"></i>
                      Active now:
                    </span>
                    <div className="d-flex align-items-center gap-1">
                      {activeUsers.slice(0, 5).map((user, idx) => (
                        <div 
                          key={idx} 
                          className="active-user-badge"
                          title={user.full_name}
                        >
                          <span className="active-indicator"></span>
                          {user.full_name?.charAt(0) || '?'}
                        </div>
                      ))}
                      {activeUsers.length > 5 && (
                        <span className="small text-muted">+{activeUsers.length - 5} more</span>
                      )}
                    </div>
                  </div>
                </div>
              )}
            </div>
            <button type="button" className="btn-close" onClick={handleClose} aria-label="Close modal"></button>
          </div>

          <div className="modal-body">
            {loading ? (
              <div className="text-center py-5">
                <div className="spinner-border" role="status">
                  <span className="visually-hidden">Loading...</span>
                </div>
              </div>
            ) : (
              <>
                {/* Issue Information Section - 2 Column Layout */}
                <div className="issue-modal-two-column">
                  {/* Left Column: Issue Details */}
                  <div className="issue-modal-left-col">
                    <div className="form-section">
                      <div className="form-section-title">
                        <i className="fas fa-info-circle"></i>
                        Issue Information
                      </div>
                      
                      {/* Issue Title with ARIA Combobox */}
                      <div className="mb-3">
                        <ComboboxAutocomplete
                          id="issue-title"
                          label="Issue Title"
                          value={formData.issue_title}
                          onChange={handleTitleChange}
                          options={titleSuggestions}
                          onSelect={selectTitleSuggestion}
                          placeholder="Enter or select issue title"
                        />
                        <button
                          type="button"
                          className="btn btn-outline-primary mt-2"
                          onClick={handleApplyPreset}
                          title="Load preset data for selected title"
                          disabled={!selectedPresetTitle}
                        >
                          <i className="fas fa-magic"></i> Apply Preset
                        </button>
                      </div>
                      
                      {/* Common Issue Title - Only show when multiple pages selected */}
                      {formData.page_ids.length > 1 && (
                        <div className="mb-3">
                          <label className="form-label">
                            <i className="fas fa-layer-group"></i>
                            Common Issue Title
                            <span className="text-muted small ms-2">(Optional - for grouping similar issues)</span>
                          </label>
                          <input
                            type="text"
                            className="form-control"
                            value={commonIssueTitle}
                            onChange={(e) => setCommonIssueTitle(e.target.value)}
                            placeholder="Enter common title for all selected pages"
                          />
                        </div>
                      )}

                      {/* Issue Details */}
                      <div className="mb-0">
                        <div className="d-flex justify-content-between align-items-center mb-2">
                          <label className="form-label mb-0">
                            <i className="fas fa-file-alt"></i>
                            Issue Details
                          </label>
                          <button
                            type="button"
                            className="btn btn-xs btn-outline-info"
                            onClick={handleResetToTemplate}
                          >
                            <i className="fas fa-undo"></i> Reset to Template
                          </button>
                        </div>
                        <div>
                          <SummernoteEditor
                            value={formData.issue_details}
                            onChange={value => setFormData(prev => ({ ...prev, issue_details: value }))}
                            minHeight={150}
                            projectId={projectId}
                          />
                        </div>
                      </div>

                      {/* Activity Section - Inside Left Column */}
                      {mode === 'edit' && issue?.id && (
                        <div className="mt-3">
                          {/* Tabs: Chat, History, Visit History */}
                          <ul className="nav nav-tabs">
                            <li className="nav-item">
                              <button
                                className={`nav-link ${activeTab === 'chat' ? 'active' : ''}`}
                                onClick={() => setActiveTab('chat')}
                              >
                                Chat / Comments
                                <span className="badge bg-secondary ms-1">{comments.filter(c => !c.deleted_at).length}</span>
                              </button>
                            </li>
                            <li className="nav-item">
                              <button
                                className={`nav-link ${activeTab === 'history' ? 'active' : ''}`}
                                onClick={() => setActiveTab('history')}
                              >
                                Edit History
                              </button>
                            </li>
                            <li className="nav-item">
                              <button
                                className={`nav-link ${activeTab === 'visit' ? 'active' : ''}`}
                                onClick={() => setActiveTab('visit')}
                              >
                                Visit History
                              </button>
                            </li>
                          </ul>

                          <div className="tab-content mt-3">
                      {activeTab === 'chat' && (
                        <div>
                          {!isClient && (
                            <>
                              <div className="mb-3">
                                <label className="form-label small fw-bold">Comment Type</label>
                                <select
                                  className="form-select form-select-sm"
                                  style={{ maxWidth: '200px' }}
                                  value={commentType}
                                  onChange={e => setCommentType(e.target.value)}
                                >
                                  <option value="normal">Normal Comment</option>
                                  <option value="regression">Regression Comment</option>
                                </select>
                              </div>
                              
                              {replyToComment && (
                                <div className="reply-indicator mb-2">
                                  <div className="d-flex align-items-center justify-content-between bg-light border rounded p-2">
                                    <div className="small">
                                      <i className="fas fa-reply me-1"></i>
                                      Replying to <strong>{replyToComment.user_name}</strong>
                                    </div>
                                    <button 
                                      className="btn btn-sm btn-link text-danger p-0"
                                      onClick={handleCancelReply}
                                    >
                                      <i className="fas fa-times"></i>
                                    </button>
                                  </div>
                                </div>
                              )}
                              
                              <div className="mb-3">
                                <SummernoteEditor
                                  value={newComment}
                                  onChange={setNewComment}
                                  placeholder="Type @ to mention users"
                                  minHeight={120}
                                  projectId={projectId}
                                  enableMentions={true}
                                />
                                <div className="small text-muted mt-1">
                                  <i className="fas fa-info-circle me-1"></i>
                                  Type @ to mention users
                                </div>
                              </div>
                              <div className="text-end mb-3">
                                <button
                                  className="btn btn-sm btn-primary"
                                  onClick={handleAddComment}
                                  disabled={!newComment.trim()}
                                >
                                  <i className="fas fa-paper-plane me-1"></i> 
                                  {replyToComment ? 'Reply' : 'Add Comment'}
                                </button>
                              </div>
                            </>
                          )}
                          
                          <div className="comments-list">
                            {comments.length === 0 ? (
                              <div className="text-center py-5 text-muted">
                                <i className="fas fa-comments fa-3x mb-3 opacity-25"></i>
                                <p>No comments yet.</p>
                              </div>
                            ) : (
                              comments.map((comment, idx) => {
                                const isDeleted = comment.deleted_at;
                                const isEditing = editingComment?.id === comment.id;
                                const canEdit = comment.can_edit;
                                const canDelete = comment.can_delete;
                                const isAdmin = ['admin', 'super_admin'].includes(userRole);
                                
                                // Don't show deleted comments to non-admins
                                if (isDeleted && !isAdmin) return null;
                                
                                return (
                                  <div key={idx} className={`comment-box ${isDeleted ? 'comment-deleted' : ''}`}>
                                    {comment.reply_preview && (
                                      <div className="reply-preview">
                                        <i className="fas fa-reply me-1"></i>
                                        <strong>{comment.reply_preview.user_name}:</strong>
                                        <span dangerouslySetInnerHTML={{ __html: comment.reply_preview.text?.substring(0, 100) + '...' || '' }} />
                                      </div>
                                    )}
                                    <div className="comment-header-compact">
                                      <div className="d-flex align-items-center gap-2">
                                        <strong className="comment-author">{comment.user_name || 'Unknown'}</strong>
                                        <span className="comment-time">{comment.created_at}</span>
                                        {comment.comment_type === 'regression' && (
                                          <span className="badge bg-warning text-dark">Regression</span>
                                        )}
                                        {isDeleted && (
                                          <span className="badge bg-danger">Deleted</span>
                                        )}
                                        {comment.edited_at && !isDeleted && (
                                          <span className="text-muted small">(edited)</span>
                                        )}
                                      </div>
                                    </div>
                                    
                                    {isEditing ? (
                                      <div className="comment-edit-mode">
                                        <SummernoteEditor
                                          value={editCommentText}
                                          onChange={setEditCommentText}
                                          placeholder="Edit your comment..."
                                          minHeight={100}
                                          projectId={projectId}
                                          enableMentions={true}
                                        />
                                        <div className="d-flex gap-2 mt-2">
                                          <button 
                                            className="btn btn-sm btn-primary"
                                            onClick={handleSaveEdit}
                                            disabled={!editCommentText.trim()}
                                          >
                                            <i className="fas fa-save me-1"></i> Save
                                          </button>
                                          <button 
                                            className="btn btn-sm btn-outline-secondary"
                                            onClick={handleCancelEdit}
                                          >
                                            Cancel
                                          </button>
                                        </div>
                                      </div>
                                    ) : (
                                      <>
                                        <div
                                          className="comment-body-compact"
                                          dangerouslySetInnerHTML={{ __html: comment.comment_html || comment.comment || '' }}
                                        />
                                        <div className="comment-actions">
                                          {!isDeleted && (
                                            <>
                                              <button 
                                                className="comment-action-btn"
                                                onClick={() => handleReplyToComment(comment)}
                                                title="Reply"
                                              >
                                                <i className="fas fa-reply"></i> Reply
                                              </button>
                                              {canEdit && (
                                                <button 
                                                  className="comment-action-btn"
                                                  onClick={() => handleEditComment(comment)}
                                                  title="Edit"
                                                >
                                                  <i className="fas fa-edit"></i> Edit
                                                </button>
                                              )}
                                              {canDelete && (
                                                <button 
                                                  className="comment-action-btn text-danger"
                                                  onClick={() => handleDeleteComment(comment)}
                                                  title="Delete"
                                                >
                                                  <i className="fas fa-trash"></i> Delete
                                                </button>
                                              )}
                                            </>
                                          )}
                                          {isAdmin && (comment.edited_at || isDeleted) && (
                                            <button 
                                              className="comment-action-btn text-info"
                                              onClick={() => handleToggleCommentHistory(comment)}
                                              title="View History"
                                            >
                                              <i className={`fas fa-${expandedCommentHistory === comment.id ? 'chevron-up' : 'history'}`}></i> History
                                            </button>
                                          )}
                                        </div>
                                        
                                        {/* Comment History - Only for Admins */}
                                        {isAdmin && expandedCommentHistory === comment.id && commentHistories[comment.id] && (
                                          <div className="comment-history-section mt-3 pt-3 border-top">
                                            <h6 className="small fw-bold text-muted mb-2">
                                              <i className="fas fa-history me-1"></i> Edit History
                                            </h6>
                                            {commentHistories[comment.id].length === 0 ? (
                                              <div className="text-center py-2 text-muted small">
                                                No history available
                                              </div>
                                            ) : (
                                              <div className="comment-history-list">
                                                {commentHistories[comment.id].map((historyEntry, hIdx) => (
                                                  <div key={hIdx} className="comment-history-item">
                                                    <div className="d-flex justify-content-between align-items-start mb-1">
                                                      <div>
                                                        <strong className="small">{historyEntry.acted_by_name || 'Unknown'}</strong>
                                                        <span className="badge bg-secondary ms-2 small">{historyEntry.action_type}</span>
                                                      </div>
                                                      <span className="text-muted small">{historyEntry.acted_at}</span>
                                                    </div>
                                                    {historyEntry.old_comment_html && (
                                                      <div className="history-diff-compact">
                                                        <div className="small text-muted mb-1">Previous:</div>
                                                        <div 
                                                          className="diff-removed-compact"
                                                          dangerouslySetInnerHTML={{ __html: historyEntry.old_comment_html }}
                                                        />
                                                        {historyEntry.new_comment_html && historyEntry.action_type === 'edit' && (
                                                          <>
                                                            <div className="small text-muted mb-1 mt-2">Updated to:</div>
                                                            <div 
                                                              className="diff-added-compact"
                                                              dangerouslySetInnerHTML={{ __html: historyEntry.new_comment_html }}
                                                            />
                                                          </>
                                                        )}
                                                      </div>
                                                    )}
                                                  </div>
                                                ))}
                                              </div>
                                            )}
                                          </div>
                                        )}
                                      </>
                                    )}
                                  </div>
                                );
                              })
                            )}
                          </div>
                        </div>
                      )}

                      {activeTab === 'history' && (
                        <div className="history-list">
                          {history.length === 0 ? (
                            <div className="text-center py-5 text-muted">
                              <p>No edit history available.</p>
                            </div>
                          ) : (
                            history.map((entry, idx) => (
                              <div key={idx} className="history-item">
                                <div className="history-header">
                                  <strong>{entry.user_name || 'Unknown'}</strong>
                                  <span className="text-muted small ms-2">{entry.created_at}</span>
                                </div>
                                <div className="history-body">
                                  <div className="small text-muted mb-2">
                                    <strong>Changed:</strong> {
                                      // Make field names more readable
                                      entry.field_name
                                        .replace('meta:', '')
                                        .replace('reporter_qa_status_map', 'Reporter QA Status')
                                        .replace('qa_status', 'QA Status')
                                        .replace('page_ids', 'Pages')
                                        .replace('reporter_ids', 'Reporters')
                                        .replace('issue_status', 'Issue Status')
                                        .replace('common_issue_title', 'Common Issue Title')
                                        .replace('assignee_id', 'QA Name')
                                        .replace(/_/g, ' ')
                                        .replace(/\b\w/g, l => l.toUpperCase())
                                    }
                                  </div>
                                  {(entry.old_value !== null && entry.old_value !== undefined) || 
                                   (entry.new_value !== null && entry.new_value !== undefined) ? (
                                    <div className="history-diff-inline">
                                      {generateInlineDiff(
                                        entry.old_value || '', 
                                        entry.new_value || ''
                                      )}
                                    </div>
                                  ) : (
                                    <div className="text-muted small">No changes to display</div>
                                  )}
                                </div>
                              </div>
                            ))
                          )}
                        </div>
                      )}

                      {activeTab === 'visit' && (
                        <div className="history-list">
                          {visitHistory.length === 0 ? (
                            <div className="text-center py-5 text-muted">
                              <p>No visit history available.</p>
                            </div>
                          ) : (
                            visitHistory.map((entry, idx) => (
                              <div key={idx} className="history-item">
                                <div className="history-header">
                                  <strong>{entry.user_name || 'Unknown'}</strong>
                                  <span className="text-muted small ms-2">{entry.created_at}</span>
                                </div>
                                <div className="history-body small">
                                  Viewed this issue
                                </div>
                              </div>
                            ))
                          )}
                        </div>
                      )}
                    </div>
                        </div>
                      )}
                    </div>
                  </div>

                  {/* Right Column: Metadata Sidebar */}
                  <div className="issue-modal-right-col">
                    <div className="metadata-section">
                      <div className="form-section-title mb-3">
                        <i className="fas fa-cog"></i>
                        Issue Metadata
                      </div>
                      
                      <div className="metadata-item">
                        <label className="form-label">
                          Issue Status
                        </label>
                        <Select
                          options={statusOptions}
                          value={statusOptions.find(o => String(o.value) === String(formData.issue_status_id))}
                          onChange={option => setFormData(prev => ({ ...prev, issue_status_id: option.value }))}
                          className="react-select-container"
                          classNamePrefix="react-select"
                        />
                      </div>

                      <div className="metadata-item">
                        <label className="form-label">
                          Page Name(s)
                        </label>
                        <Select
                          isMulti
                          options={pageOptions}
                          value={pageOptions.filter(o => formData.page_ids.map(String).includes(String(o.value)))}
                          onChange={options => setFormData(prev => ({ 
                            ...prev, 
                            page_ids: options.map(o => o.value) 
                          }))}
                          className="react-select-container"
                          classNamePrefix="react-select"
                        />
                        <div className="d-grid gap-2 mt-2">
                          <button
                            type="button"
                            className="btn btn-sm btn-outline-primary"
                            onClick={() => setShowUrlModal(true)}
                          >
                            <i className="fas fa-link"></i> Manage Grouped URLs
                          </button>
                          <button
                            type="button"
                            className="btn btn-sm btn-outline-secondary"
                            onClick={() => setShowGroupedUrlsPreview(!showGroupedUrlsPreview)}
                          >
                            <i className={`fas fa-chevron-${showGroupedUrlsPreview ? 'up' : 'down'}`}></i> 
                            View Grouped URLs ({groupedUrls.length})
                          </button>
                          {showGroupedUrlsPreview && (
                            <div className="border rounded p-2 bg-light small">
                              {groupedUrls.length === 0 ? (
                                <ol className="mb-0 ps-3">
                                  <li className="text-muted">No grouped URLs selected.</li>
                                </ol>
                              ) : (
                                <ol className="mb-0 ps-3">
                                  {groupedUrls.map((url, idx) => (
                                    <li key={idx}>{url}</li>
                                  ))}
                                </ol>
                              )}
                            </div>
                          )}
                          <div className="small text-muted">
                            Pages: {formData.page_ids.length} | Grouped URLs: {groupedUrls.length} selected
                          </div>
                        </div>
                      </div>

                      {!isClient && (
                        <>
                          <div className="metadata-item">
                            <label className="form-label">
                              Reporter Name(s)
                            </label>
                            <Select
                              isMulti
                              options={reporterOptions}
                              value={reporterOptions.filter(o => formData.reporter_ids.includes(o.value))}
                              onChange={options => {
                                const newReporterIds = options.map(o => o.value);
                                setFormData(prev => ({ 
                                  ...prev, 
                                  reporter_ids: newReporterIds
                                }));
                                const newMap = {};
                                newReporterIds.forEach(rid => {
                                  newMap[rid] = reporterQaStatusMap[rid] || [];
                                });
                                setReporterQaStatusMap(newMap);
                              }}
                              className="react-select-container"
                              classNamePrefix="react-select"
                            />
                            {formData.reporter_ids.length > 0 && (
                              <div className="mt-2">
                                <label className="form-label mb-1">QA Status By Reporter</label>
                                <div className="small border rounded p-2 bg-light">
                                  {formData.reporter_ids.map(reporterId => {
                                    const reporter = reporterOptions.find(r => r.value === reporterId);
                                    const qaStatusOptions = qaStatuses.map(qs => ({
                                      value: qs.status_key,
                                      label: qs.status_label || qs.status_key
                                    }));
                                    const selectedQaStatuses = reporterQaStatusMap[reporterId] || [];
                                    
                                    return (
                                      <div key={reporterId} className="row g-2 align-items-center mb-2">
                                        <div className="col-5">
                                          <span className="fw-semibold">{reporter?.label || 'Unknown'}</span>
                                        </div>
                                        <div className="col-7">
                                          <Select
                                            isMulti
                                            options={qaStatusOptions}
                                            value={qaStatusOptions.filter(o => selectedQaStatuses.includes(o.value))}
                                            onChange={opts => {
                                              setReporterQaStatusMap(prev => ({
                                                ...prev,
                                                [reporterId]: opts.map(o => o.value)
                                              }));
                                            }}
                                            className="react-select-container"
                                            classNamePrefix="react-select"
                                          />
                                        </div>
                                      </div>
                                    );
                                  })}
                                </div>
                                <small className="text-muted">This mapping is used for reporter-wise performance scoring.</small>
                              </div>
                            )}
                          </div>

                          <div className="metadata-item">
                            <label className="form-label">
                              QA Name
                            </label>
                            <Select
                              options={reporterOptions}
                              value={reporterOptions.find(o => o.value === formData.assignee_id)}
                              onChange={option => setFormData(prev => ({ 
                                ...prev, 
                                assignee_id: option ? option.value : null
                              }))}
                              className="react-select-container"
                              classNamePrefix="react-select"
                              isClearable
                              placeholder="Select QA..."
                            />
                          </div>
                        </>
                      )}

                      {/* Dynamic Metadata Fields */}
                      {metadataFields.map(field => {
                        const options = (field.options || []).map(opt => {
                          if (typeof opt === 'string') {
                            return { value: opt, label: opt };
                          } else if (opt && typeof opt === 'object') {
                            return { value: opt.option_value, label: opt.option_label };
                          }
                          return null;
                        }).filter(Boolean);

                        const values = formData.metadata[field.field_key];
                        const selectedValues = Array.isArray(values) 
                          ? values 
                          : (values ? String(values).split(',').map(v => v.trim()).filter(Boolean) : []);
                        
                        return (
                          <div key={field.id} className="metadata-item">
                            <label className="form-label">
                              {field.field_label}
                            </label>
                            <CreatableSelect
                              isMulti
                              options={options}
                              value={options.filter(o => selectedValues.includes(o.value))}
                              onChange={opts => handleMetadataChange(
                                field.field_key, 
                                opts ? opts.map(o => o.value).join(', ') : ''
                              )}
                              className="react-select-container"
                              classNamePrefix="react-select"
                              placeholder={`Select or type ${field.field_label}...`}
                            />
                          </div>
                        );
                      })}

                      {!isClient && ['qa', 'project_lead', 'admin', 'super_admin'].includes(userRole) && (
                        <div className="metadata-item pt-3 border-top">
                          <div className="form-check">
                            <input
                              className="form-check-input"
                              type="checkbox"
                              id="clientReady"
                              checked={formData.client_ready}
                              onChange={e => setFormData(prev => ({ ...prev, client_ready: e.target.checked }))}
                            />
                            <label className="form-check-label" htmlFor="clientReady">
                              Client Ready
                            </label>
                            <div className="form-text small">Mark this issue as ready for client viewing</div>
                          </div>
                        </div>
                      )}
                    </div>
                  </div>
                </div>
              </>
            )}
          </div>

          <div className="modal-footer">
            <button className="btn btn-outline-secondary" onClick={onClose} disabled={saving}>
              Cancel
            </button>
            <button className="btn btn-primary" onClick={handleSave} disabled={saving || loading}>
              {saving ? 'Saving...' : 'Save'}
            </button>
          </div>
        </div>
      </div>

      {/* Grouped URLs Modal */}
      {showUrlModal && (
        <div className="modal-overlay" onClick={() => setShowUrlModal(false)} style={{ zIndex: 1060 }}>
          <div className="modal-dialog modal-lg" onClick={e => e.stopPropagation()}>
            <div className="modal-content">
              <div className="modal-header">
                <h5 className="modal-title">Manage Page Name(s) & Grouped URLs</h5>
                <button type="button" className="btn-close" onClick={() => setShowUrlModal(false)}></button>
              </div>
              <div className="modal-body">
                <div className="mb-3">
                  <label className="form-label fw-bold">Page Name(s)</label>
                  <Select
                    isMulti
                    options={pageOptions}
                    value={pageOptions.filter(o => formData.page_ids.includes(o.value))}
                    onChange={options => setFormData(prev => ({ 
                      ...prev, 
                      page_ids: options.map(o => o.value) 
                    }))}
                    className="react-select-container"
                    classNamePrefix="react-select"
                  />
                  <div className="form-text">Select one or multiple pages for this issue.</div>
                </div>

                <div className="mb-3">
                  <label className="form-label fw-bold">Grouped URLs</label>
                  <CreatableSelect
                    isMulti
                    options={availableGroupedUrls.map(url => ({ value: url, label: url }))}
                    value={groupedUrls.map(url => ({ value: url, label: url }))}
                    onChange={options => setGroupedUrls(options ? options.map(o => o.value) : [])}
                    className="react-select-container"
                    classNamePrefix="react-select"
                    placeholder="Search, select, or type custom URL and press Enter"
                  />
                  <div className="form-text">Search, select, or type custom URL and press Enter to add it.</div>
                </div>
              </div>
              <div className="modal-footer">
                <button className="btn btn-outline-secondary" onClick={() => setShowUrlModal(false)}>
                  Close
                </button>
                <button className="btn btn-primary" onClick={() => setShowUrlModal(false)}>
                  Done
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Edit Conflict Warning Modal */}
      {showConflictWarning && (
        <div 
          className="modal-overlay" 
          onClick={(e) => e.stopPropagation()} 
          style={{ zIndex: 1070 }}
        >
          <div 
            className="bg-white rounded shadow-lg p-4" 
            onClick={e => e.stopPropagation()}
            style={{ maxWidth: '500px', width: '90%' }}
            role="alertdialog"
            aria-modal="true"
            aria-labelledby="conflict-warning-title"
          >
            <div className="text-center mb-3">
              <i className="fas fa-exclamation-triangle text-warning fa-3x mb-3"></i>
              <h5 id="conflict-warning-title">Edit Conflict Detected</h5>
            </div>
            <div className="alert alert-warning mb-3">
              <strong>Someone else has modified this issue while you were editing.</strong>
              <p className="mb-0 mt-2 small">
                The issue has been updated by another user. You can either:
              </p>
              <ul className="small mb-0 mt-2">
                <li>Reload to see the latest changes (your changes will be lost)</li>
                <li>Overwrite with your changes (other user's changes will be lost)</li>
              </ul>
            </div>
            <div className="d-flex gap-2 justify-content-center">
              <button 
                className="btn btn-outline-primary" 
                onClick={handleReloadConflict}
              >
                <i className="fas fa-sync me-1"></i> Reload Latest
              </button>
              <button 
                className="btn btn-warning" 
                onClick={handleOverwriteConflict}
              >
                <i className="fas fa-save me-1"></i> Overwrite & Save
              </button>
              <button 
                className="btn btn-outline-secondary" 
                onClick={() => setShowConflictWarning(false)}
              >
                Cancel
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Unsaved Changes Warning Modal */}
      {showUnsavedWarning && (
        <div 
          className="modal-overlay" 
          onClick={(e) => e.stopPropagation()} 
          style={{ zIndex: 1070 }}
          onKeyDown={(e) => {
            if (e.key === 'Escape') {
              e.preventDefault();
              e.stopPropagation();
              handleKeepEditing();
            } else if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
              e.preventDefault();
              const buttons = e.currentTarget.querySelectorAll('button:not([disabled])');
              const currentIndex = Array.from(buttons).indexOf(document.activeElement);
              if (currentIndex !== -1) {
                let nextIndex;
                if (e.key === 'ArrowLeft') {
                  nextIndex = currentIndex > 0 ? currentIndex - 1 : buttons.length - 1;
                } else {
                  nextIndex = currentIndex < buttons.length - 1 ? currentIndex + 1 : 0;
                }
                buttons[nextIndex]?.focus();
              }
            }
          }}
        >
          <div 
            className="bg-white rounded shadow-lg p-4 text-center" 
            onClick={e => e.stopPropagation()}
            style={{ maxWidth: '450px', width: '90%' }}
            role="alertdialog"
            aria-modal="true"
            aria-labelledby="unsaved-warning-title"
            aria-describedby="unsaved-warning-desc"
            ref={(el) => {
              if (el) {
                // Focus first button when dialog opens
                setTimeout(() => {
                  const firstButton = el.querySelector('button:not([disabled])');
                  firstButton?.focus();
                }, 100);
              }
            }}
          >
            <i className="fas fa-exclamation-triangle text-warning fa-3x mb-3"></i>
            <h6 className="mb-3" id="unsaved-warning-title">Unsaved Changes</h6>
            <p className="small text-muted mb-4" id="unsaved-warning-desc">
              You have unsaved changes. What would you like to do?
            </p>
            <div className="d-flex gap-2 justify-content-center">
              <button 
                className="btn btn-outline-secondary btn-sm" 
                onClick={handleKeepEditing}
                autoFocus
              >
                Keep Editing
              </button>
              <button 
                className="btn btn-primary btn-sm" 
                onClick={handleSave}
                disabled={saving}
              >
                {saving ? 'Saving...' : 'Save Changes'}
              </button>
              <button 
                className="btn btn-outline-danger btn-sm" 
                onClick={handleDiscardChanges}
              >
                Discard
              </button>
            </div>
          </div>
        </div>
      )}
      
      {/* Delete Comment Confirmation Modal */}
      <ConfirmModal
        isOpen={showDeleteCommentConfirm}
        onClose={() => setShowDeleteCommentConfirm(false)}
        onConfirm={confirmDeleteComment}
        title="Delete Comment"
        message="Are you sure you want to delete this comment? This action cannot be undone."
        confirmText="Delete"
        cancelText="Cancel"
        confirmButtonClass="btn-danger"
        icon="fa-trash"
      />
    </div>
  );
};

export default IssueModal;
