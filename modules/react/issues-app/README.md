# Issues App - React Version

A modern, full-featured React application for managing accessibility issues in the PMS system.

## Features

### Core Functionality
- **Issue Management**: Create, edit, delete, and view issues
- **Rich Text Editor**: Quill-based editor with formatting, lists, colors, and images
- **Comments System**: Add and view comments on issues
- **Multiple Views**:
  - All Issues: Complete list of all issues
  - By Pages: Filter issues by project pages with sidebar navigation
  - Common Issues: View common/global issues

### Advanced Features
- **Filters & Search**: 
  - Full-text search across title, description, and issue key
  - Filter by status, severity, and priority
  - Show/hide advanced filters
  - Reset all filters
- **Dynamic Metadata**: Automatically loads and displays custom metadata fields from backend
- **Expandable Rows**: Click to expand issue details and comments
- **Optimistic UI Updates**: Instant feedback on create/edit/delete operations
- **Performance Optimized**: 
  - React.memo for component memoization
  - useMemo for expensive computations
  - Debounced API calls

### UI/UX
- **Responsive Design**: Works on desktop, tablet, and mobile
- **Bootstrap 5**: Modern, clean interface
- **Font Awesome Icons**: Consistent iconography
- **Loading States**: Spinners and loaders for async operations
- **Error Handling**: User-friendly error messages
- **Badge System**: Color-coded badges for status, severity, priority

## Tech Stack

- **React 19**: Latest React with hooks
- **Vite**: Fast build tool and dev server
- **Zustand**: Lightweight state management
- **React Hook Form**: Form validation and management
- **React Quill**: Rich text editor
- **React Router DOM**: Client-side routing
- **Axios**: HTTP client
- **Bootstrap 5**: CSS framework
- **Font Awesome**: Icon library

## Project Structure

```
src/
├── components/
│   ├── Common/           # Reusable components
│   │   ├── Badge.jsx
│   │   ├── Button.jsx
│   │   └── Loader.jsx
│   ├── Comments/         # Comments component
│   │   ├── Comments.jsx
│   │   └── Comments.css
│   ├── Filters/          # Search and filter component
│   │   ├── Filters.jsx
│   │   └── Filters.css
│   ├── IssueModal/       # Create/Edit modal
│   │   ├── IssueModal.jsx
│   │   └── IssueModal.css
│   ├── IssueTable/       # Issues table and rows
│   │   ├── IssueTable.jsx
│   │   └── IssueRow.jsx
│   └── PageList/         # Page sidebar navigation
│       ├── PageList.jsx
│       └── PageList.css
├── services/             # API layer
│   ├── api.js           # Base API service
│   └── issuesApi.js     # Issues-specific API
├── store/               # State management
│   └── issuesStore.js   # Zustand store
├── utils/               # Utility functions
│   └── formatters.js    # Date, badge, parsing utilities
├── App.jsx              # Main app component
├── App.css              # Global styles
└── main.jsx             # Entry point
```

## Development

### Install Dependencies
```bash
npm install
```

### Development Server
```bash
npm run dev
```
Runs on http://localhost:5173

### Build for Production
```bash
npm run build
```
Outputs to `dist/` folder

### Preview Production Build
```bash
npm run preview
```

## Integration with PHP Backend

The React app integrates seamlessly with the existing PHP backend:

1. **Entry Point**: `modules/projects/issues-react.php`
   - Loads React app
   - Passes session data via `window.APP_CONFIG`
   - Includes user info, project ID, permissions

2. **API Endpoints**: Uses existing PHP APIs
   - `/api/issues.php` - Issue CRUD operations
   - `/api/issue_comments.php` - Comments
   - `/api/issue_templates.php` - Metadata options
   - No backend changes required!

3. **Authentication**: Uses PHP session
   - Session validated on each API call
   - Automatic logout on session expiry

## State Management

Uses Zustand for simple, efficient state management:

```javascript
const useIssuesStore = create((set, get) => ({
  // State
  issues: [],
  loading: false,
  error: null,
  
  // Actions
  fetchIssues: async (projectId) => { ... },
  createIssue: async (data) => { ... },
  updateIssue: async (id, data) => { ... },
  deleteIssue: async (id) => { ... },
}));
```

## Performance Optimizations

1. **Component Memoization**: `React.memo` on IssueRow
2. **Computed Values**: `useMemo` for filtered/sorted data
3. **Optimistic Updates**: UI updates before API confirmation
4. **Debounced Search**: Prevents excessive API calls
5. **Lazy Loading**: Components loaded on demand

## Browser Support

- Chrome/Edge: Latest 2 versions
- Firefox: Latest 2 versions
- Safari: Latest 2 versions

## Future Enhancements

- [ ] Bulk operations (select multiple issues)
- [ ] Export to PDF/Excel
- [ ] Drag-and-drop file uploads
- [ ] Real-time updates with WebSockets
- [ ] Keyboard shortcuts
- [ ] Dark mode
- [ ] Offline support with service workers
- [ ] Advanced analytics dashboard

## Troubleshooting

### Build Errors
If you encounter peer dependency issues with React 19:
```bash
npm install --legacy-peer-deps
```

### API Errors
Check browser console for detailed error messages. Ensure:
- PHP session is active
- User has project access
- API endpoints are accessible

### Styling Issues
Clear browser cache and rebuild:
```bash
npm run build
```

## License

Internal use only - Athenaeum Transformation
