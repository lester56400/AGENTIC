
import sys
import os

# Add the script directory to path to import core and design_system
sys.path.append(os.path.abspath('.agent/.shared/ui-ux-pro-max/scripts'))

from design_system import generate_design_system

def main():
    query = "wordpress seo silo internal linking dashboard graph visualization professional analytical"
    project_name = "Smart Internal Links"
    output_format = "markdown"
    
    try:
        result = generate_design_system(
            query, 
            project_name, 
            output_format,
            persist=False
        )
        
        with open('ui_search_results.md', 'w', encoding='utf-8') as f:
            f.write(result)
        print("Success: Results written to ui_search_results.md")
    except Exception as e:
        print(f"Error: {e}")
        sys.exit(1)

if __name__ == "__main__":
    main()
