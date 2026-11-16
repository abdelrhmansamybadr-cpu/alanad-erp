import { useState } from "react";
import { Palette, Type, Layout, Layers, Zap, CheckCircle, AlertCircle, Info, XCircle } from "lucide-react";

export function DesignSystem() {
  const [activeSection, setActiveSection] = useState("colors");

  const sections = [
    { id: "colors", label: "Colors", icon: Palette },
    { id: "typography", label: "Typography", icon: Type },
    { id: "components", label: "Components", icon: Layout },
    { id: "icons", label: "Icons", icon: Layers },
    { id: "spacing", label: "Spacing", icon: Zap },
  ];

  return (
    <div className="p-8">
      {/* Header */}
      <div className="mb-8">
        <h1 className="text-3xl font-bold text-gray-900 mb-2">Design System</h1>
        <p className="text-gray-600">Comprehensive design guidelines and component library</p>
      </div>

      <div className="flex gap-6">
        {/* Sidebar */}
        <div className="w-64 bg-white rounded-lg shadow-sm border border-gray-200 p-4 h-fit sticky top-8">
          <nav className="space-y-1">
            {sections.map((section) => (
              <button
                key={section.id}
                onClick={() => setActiveSection(section.id)}
                className={`w-full flex items-center gap-3 px-4 py-3 rounded-lg transition-colors ${
                  activeSection === section.id
                    ? "bg-blue-50 text-blue-700 font-medium"
                    : "text-gray-700 hover:bg-gray-50"
                }`}
              >
                <section.icon className="w-5 h-5" />
                <span>{section.label}</span>
              </button>
            ))}
          </nav>
        </div>

        {/* Content */}
        <div className="flex-1">
          {activeSection === "colors" && <ColorsSection />}
          {activeSection === "typography" && <TypographySection />}
          {activeSection === "components" && <ComponentsSection />}
          {activeSection === "icons" && <IconsSection />}
          {activeSection === "spacing" && <SpacingSection />}
        </div>
      </div>
    </div>
  );
}

function ColorsSection() {
  const colorGroups = [
    {
      name: "Primary",
      colors: [
        { name: "Blue 50", hex: "#EFF6FF", var: "--color-blue-50" },
        { name: "Blue 100", hex: "#DBEAFE", var: "--color-blue-100" },
        { name: "Blue 600", hex: "#2563EB", var: "--color-blue-600" },
        { name: "Blue 700", hex: "#1D4ED8", var: "--color-blue-700" },
        { name: "Blue 900", hex: "#1E3A8A", var: "--color-blue-900" },
      ],
    },
    {
      name: "Neutral",
      colors: [
        { name: "Gray 50", hex: "#F9FAFB", var: "--color-gray-50" },
        { name: "Gray 100", hex: "#F3F4F6", var: "--color-gray-100" },
        { name: "Gray 600", hex: "#4B5563", var: "--color-gray-600" },
        { name: "Gray 900", hex: "#111827", var: "--color-gray-900" },
      ],
    },
    {
      name: "Semantic",
      colors: [
        { name: "Success", hex: "#10B981", var: "--color-green-500" },
        { name: "Warning", hex: "#F59E0B", var: "--color-orange-500" },
        { name: "Error", hex: "#EF4444", var: "--color-red-500" },
        { name: "Info", hex: "#3B82F6", var: "--color-blue-500" },
      ],
    },
  ];

  return (
    <div className="space-y-8">
      {colorGroups.map((group) => (
        <div key={group.name} className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
          <h2 className="text-xl font-bold text-gray-900 mb-4">{group.name} Colors</h2>
          <div className="grid grid-cols-1 md:grid-cols-5 gap-4">
            {group.colors.map((color) => (
              <div key={color.name} className="space-y-2">
                <div
                  className="h-24 rounded-lg border border-gray-200 shadow-sm"
                  style={{ backgroundColor: color.hex }}
                />
                <div>
                  <div className="font-medium text-gray-900 text-sm">{color.name}</div>
                  <div className="text-xs text-gray-600">{color.hex}</div>
                  <div className="text-xs text-gray-500 font-mono">{color.var}</div>
                </div>
              </div>
            ))}
          </div>
        </div>
      ))}
    </div>
  );
}

function TypographySection() {
  return (
    <div className="space-y-8">
      <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h2 className="text-xl font-bold text-gray-900 mb-4">Headings</h2>
        <div className="space-y-4">
          <div>
            <h1 className="text-4xl font-bold text-gray-900">Heading 1</h1>
            <p className="text-sm text-gray-500 mt-1">text-4xl font-bold</p>
          </div>
          <div>
            <h2 className="text-3xl font-bold text-gray-900">Heading 2</h2>
            <p className="text-sm text-gray-500 mt-1">text-3xl font-bold</p>
          </div>
          <div>
            <h3 className="text-2xl font-bold text-gray-900">Heading 3</h3>
            <p className="text-sm text-gray-500 mt-1">text-2xl font-bold</p>
          </div>
          <div>
            <h4 className="text-xl font-bold text-gray-900">Heading 4</h4>
            <p className="text-sm text-gray-500 mt-1">text-xl font-bold</p>
          </div>
        </div>
      </div>

      <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h2 className="text-xl font-bold text-gray-900 mb-4">Body Text</h2>
        <div className="space-y-4">
          <div>
            <p className="text-base text-gray-900">
              Body Text Large - The quick brown fox jumps over the lazy dog. This is the standard body text used throughout the application.
            </p>
            <p className="text-sm text-gray-500 mt-1">text-base</p>
          </div>
          <div>
            <p className="text-sm text-gray-900">
              Body Text Small - The quick brown fox jumps over the lazy dog. This is used for secondary information.
            </p>
            <p className="text-sm text-gray-500 mt-1">text-sm</p>
          </div>
          <div>
            <p className="text-xs text-gray-900">
              Caption Text - The quick brown fox jumps over the lazy dog. This is used for labels and captions.
            </p>
            <p className="text-sm text-gray-500 mt-1">text-xs</p>
          </div>
        </div>
      </div>

      <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h2 className="text-xl font-bold text-gray-900 mb-4">Font Weights</h2>
        <div className="space-y-3">
          <div className="text-normal">Regular - font-normal (400)</div>
          <div className="font-medium">Medium - font-medium (500)</div>
          <div className="font-semibold">Semibold - font-semibold (600)</div>
          <div className="font-bold">Bold - font-bold (700)</div>
        </div>
      </div>
    </div>
  );
}

function ComponentsSection() {
  return (
    <div className="space-y-8">
      {/* Buttons */}
      <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h2 className="text-xl font-bold text-gray-900 mb-4">Buttons</h2>
        <div className="space-y-4">
          <div className="flex flex-wrap gap-3">
            <button className="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-medium">
              Primary Button
            </button>
            <button className="px-6 py-2 bg-white border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors font-medium">
              Secondary Button
            </button>
            <button className="px-6 py-2 text-blue-600 hover:bg-blue-50 rounded-lg transition-colors font-medium">
              Text Button
            </button>
            <button className="px-6 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors font-medium">
              Danger Button
            </button>
          </div>
          
          <div className="flex flex-wrap gap-3">
            <button className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors text-sm font-medium">
              Small Button
            </button>
            <button className="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors text-lg font-medium">
              Large Button
            </button>
          </div>
        </div>
      </div>

      {/* Form Inputs */}
      <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h2 className="text-xl font-bold text-gray-900 mb-4">Form Inputs</h2>
        <div className="space-y-4 max-w-md">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">Text Input</label>
            <input
              type="text"
              placeholder="Enter text..."
              className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">Select Dropdown</label>
            <select className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
              <option>Option 1</option>
              <option>Option 2</option>
              <option>Option 3</option>
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">Textarea</label>
            <textarea
              rows={3}
              placeholder="Enter longer text..."
              className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>
        </div>
      </div>

      {/* Alerts */}
      <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h2 className="text-xl font-bold text-gray-900 mb-4">Alerts</h2>
        <div className="space-y-3">
          <div className="bg-green-50 border border-green-200 rounded-lg p-4 flex items-start gap-3">
            <CheckCircle className="w-5 h-5 text-green-600 mt-0.5" />
            <div>
              <div className="font-medium text-green-900">Success</div>
              <div className="text-sm text-green-800">Operation completed successfully!</div>
            </div>
          </div>
          <div className="bg-blue-50 border border-blue-200 rounded-lg p-4 flex items-start gap-3">
            <Info className="w-5 h-5 text-blue-600 mt-0.5" />
            <div>
              <div className="font-medium text-blue-900">Information</div>
              <div className="text-sm text-blue-800">Here's some useful information.</div>
            </div>
          </div>
          <div className="bg-orange-50 border border-orange-200 rounded-lg p-4 flex items-start gap-3">
            <AlertCircle className="w-5 h-5 text-orange-600 mt-0.5" />
            <div>
              <div className="font-medium text-orange-900">Warning</div>
              <div className="text-sm text-orange-800">Please be careful with this action.</div>
            </div>
          </div>
          <div className="bg-red-50 border border-red-200 rounded-lg p-4 flex items-start gap-3">
            <XCircle className="w-5 h-5 text-red-600 mt-0.5" />
            <div>
              <div className="font-medium text-red-900">Error</div>
              <div className="text-sm text-red-800">Something went wrong. Please try again.</div>
            </div>
          </div>
        </div>
      </div>

      {/* Cards */}
      <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h2 className="text-xl font-bold text-gray-900 mb-4">Cards</h2>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <h3 className="font-bold text-gray-900 mb-2">Basic Card</h3>
            <p className="text-gray-600">This is a basic card with border and shadow.</p>
          </div>
          <div className="bg-gradient-to-r from-blue-600 to-blue-700 rounded-lg shadow-sm p-6">
            <h3 className="font-bold text-white mb-2">Gradient Card</h3>
            <p className="text-blue-100">This card has a gradient background.</p>
          </div>
        </div>
      </div>
    </div>
  );
}

function IconsSection() {
  const iconCategories = [
    {
      name: "Common",
      icons: [
        { Icon: CheckCircle, name: "CheckCircle" },
        { Icon: AlertCircle, name: "AlertCircle" },
        { Icon: Info, name: "Info" },
        { Icon: XCircle, name: "XCircle" },
      ],
    },
    {
      name: "Interface",
      icons: [
        { Icon: Layout, name: "Layout" },
        { Icon: Palette, name: "Palette" },
        { Icon: Type, name: "Type" },
        { Icon: Layers, name: "Layers" },
      ],
    },
  ];

  return (
    <div className="space-y-6">
      {iconCategories.map((category) => (
        <div key={category.name} className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
          <h2 className="text-xl font-bold text-gray-900 mb-4">{category.name} Icons</h2>
          <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
            {category.icons.map(({ Icon, name }) => (
              <div key={name} className="flex flex-col items-center gap-2 p-4 border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors">
                <Icon className="w-8 h-8 text-gray-700" />
                <span className="text-sm text-gray-600 font-mono">{name}</span>
              </div>
            ))}
          </div>
        </div>
      ))}
      <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
        <p className="text-sm text-blue-800">
          Icons from <strong>lucide-react</strong> library. Visit{" "}
          <a href="https://lucide.dev" className="underline font-medium" target="_blank" rel="noopener noreferrer">
            lucide.dev
          </a>{" "}
          for the complete icon set.
        </p>
      </div>
    </div>
  );
}

function SpacingSection() {
  const spacingValues = [
    { size: "1", px: "4px", class: "p-1" },
    { size: "2", px: "8px", class: "p-2" },
    { size: "3", px: "12px", class: "p-3" },
    { size: "4", px: "16px", class: "p-4" },
    { size: "6", px: "24px", class: "p-6" },
    { size: "8", px: "32px", class: "p-8" },
    { size: "12", px: "48px", class: "p-12" },
    { size: "16", px: "64px", class: "p-16" },
  ];

  return (
    <div className="space-y-8">
      <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h2 className="text-xl font-bold text-gray-900 mb-4">Spacing Scale</h2>
        <div className="space-y-4">
          {spacingValues.map((spacing) => (
            <div key={spacing.size} className="flex items-center gap-4">
              <div className="w-20 text-sm font-mono text-gray-700">{spacing.class}</div>
              <div className="w-24 text-sm text-gray-600">{spacing.px}</div>
              <div className="flex-1">
                <div className="bg-blue-600" style={{ width: spacing.px, height: "24px" }} />
              </div>
            </div>
          ))}
        </div>
      </div>

      <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h2 className="text-xl font-bold text-gray-900 mb-4">Border Radius</h2>
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          {[
            { name: "None", class: "rounded-none", value: "0px" },
            { name: "Small", class: "rounded", value: "4px" },
            { name: "Medium", class: "rounded-md", value: "6px" },
            { name: "Large", class: "rounded-lg", value: "8px" },
            { name: "XL", class: "rounded-xl", value: "12px" },
            { name: "2XL", class: "rounded-2xl", value: "16px" },
            { name: "Full", class: "rounded-full", value: "9999px" },
          ].map((radius) => (
            <div key={radius.name} className="text-center">
              <div className={`h-24 bg-blue-600 mb-2 ${radius.class}`} />
              <div className="font-medium text-gray-900 text-sm">{radius.name}</div>
              <div className="text-xs text-gray-500 font-mono">{radius.class}</div>
              <div className="text-xs text-gray-500">{radius.value}</div>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
