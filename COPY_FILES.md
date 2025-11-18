# Absolute beginner guide: getting the three PDF files into VSCodium

This walkthrough assumes you have **never copied files out of a Codespace-style container before**. Follow the steps in order—no prior Docker or terminal knowledge is required.

---

## A. Understand what you're copying

You need the following three PHP files from this repository:

1. `includes/class-traxs-workorder-pdf.php`
2. `includes/pdf/class-traxs-workorder-sections.php`
3. `package/traxs/includes/class-traxs-workorder-pdf.php`

Each file lives inside the `/workspace/traxs` folder (that path is shown in the terminal prompt). Your goal is to open each file, copy all of its text, and paste that text into matching files inside VSCodium so you can test changes locally.

---

## B. Open the built-in terminal (inside this workspace)

1. In the Codespace/VS Code/VSCodium window, look for the **Terminal** panel (usually at the bottom). If you do not see one, press <kbd>Ctrl</kbd> + <kbd>`</kbd> (backtick) or use the menu **Terminal → New Terminal**.
2. Make sure the prompt ends with `/workspace/traxs#`. If it does not, type:
   ```bash
   cd /workspace/traxs
   ```
3. Press <kbd>Enter</kbd>. The prompt should now say `root@…:/workspace/traxs#`.

---

## C. Copy each file (one at a time)

### 1. Show the first file in the terminal
```bash
cat includes/class-traxs-workorder-pdf.php
```
- The terminal will fill with text.
- Scroll to the very top of that output.
- Click and drag from the first line to the very bottom so every line is highlighted.
- Press <kbd>Ctrl</kbd> + <kbd>C</kbd> to copy.
- Switch to VSCodium on your computer, open or create a file named `class-traxs-workorder-pdf.php`, and paste with <kbd>Ctrl</kbd> + <kbd>V</kbd>.
- Save the file.

### 2. Repeat for the sections trait
```bash
cat includes/pdf/class-traxs-workorder-sections.php
```
Follow the **exact** same highlight → copy → paste process, but save this text into `class-traxs-workorder-sections.php` (inside whatever folder you want in VSCodium).

### 3. Repeat for the packaged copy
```bash
cat package/traxs/includes/class-traxs-workorder-pdf.php
```
Copy all of it and paste into `class-traxs-workorder-pdf.php` in your local `package/traxs/includes/` folder (create those folders if they do not exist yet).

> ✅ Once you have pasted all three files, your local VSCodium project now matches the files from the container. You can edit and test them normally.

---

## D. (Optional) Download the files in one zip
If selecting and copying text feels error-prone, you can create a zip file inside the container and then download it.

1. In the same terminal, run:
   ```bash
   cd /workspace/traxs
   zip -r workorder-files.zip \
       includes/class-traxs-workorder-pdf.php \
       includes/pdf/class-traxs-workorder-sections.php \
       package/traxs/includes/class-traxs-workorder-pdf.php
   ```
2. This command creates `workorder-files.zip` in `/workspace/traxs`.
3. Use your environment's download feature (for GitHub Codespaces/VS Code: right-click the file in the Explorer and choose **Download…**).
4. Unzip `workorder-files.zip` on your computer and open the extracted PHP files in VSCodium.

---

## E. Need a refresher later?
Come back to this document (`COPY_FILES.md`) any time—you can open it directly in VSCodium to review the steps.
