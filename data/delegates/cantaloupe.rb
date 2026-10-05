# Cantaloupe delegate script for Omeka S module Image Server.
#
# Case A: resolve a clean, extension-less IIIF identifier (the Omeka media
# "storage id", option "Filename" of "Media identifier") to an original file,
# so an external Cantaloupe sharing the Omeka "files/original" directory can be
# used without exposing the file extension in the IIIF url. The identifier with
# extension (option "Filename with extension") is supported too.
#
# Resolution is filesystem only (no database, no directory glob): the storage
# id is a flat hash and the originals directory may hold hundreds of thousands
# of files, so each candidate extension is probed with a direct stat, which is
# O(1) on a htree/B-tree directory index. Never use Dir.glob here.
#
# Images only: audio and video are served by Image Server / Iiif Server, not by
# Cantaloupe, so they never reach this delegate.
#
# Install:
#   1. Copy to /etc/cantaloupe/delegates.rb (or mount it in the container).
#   2. In cantaloupe.properties:
#        delegate_script.enabled            = true
#        delegate_script.pathname           = /etc/cantaloupe/delegates.rb
#        source.static                      = FilesystemSource
#        FilesystemSource.lookup_strategy   = ScriptLookupStrategy
#   3. Set ORIGINAL_DIR below to the originals directory as seen from inside
#      the Cantaloupe container.
#   4. Restart Cantaloupe.
#
# Coexistence with module Access: Cantaloupe loads a single delegates.rb. When
# the Access authorization delegate is also used, merge its "pre_authorize" /
# "fetch_decision" methods into this class (a single CustomDelegate), keeping
# the "filesystemsource_pathname" / "source" methods below.

class CustomDelegate

  attr_accessor :context

  # Originals directory, as mounted inside the Cantaloupe container.
  ORIGINAL_DIR = ENV['OMEKA_ORIGINAL_DIR'] || '/files/original'

  # Image extensions probed for an extension-less identifier, most frequent
  # first. Audio and video are intentionally excluded (served by Iiif Server).
  IMAGE_EXTENSIONS = %w[jpg jpeg png tif tiff jp2 gif webp].freeze

  def source(options = {})
    'FilesystemSource'
  end

  def filesystemsource_pathname(options = {})
    identifier = context['identifier'].to_s
    return nil if identifier.empty?

    # Reject path traversal and any slash: the storage id is a flat hash, and
    # slash-bearing identifiers (e.g. arks) are handled by another resolver.
    return nil if identifier.include?('..') || identifier.include?('/')

    # 1. Identifier already carries the extension: use it directly.
    candidate = File.join(ORIGINAL_DIR, identifier)
    return candidate if File.file?(candidate)

    # 2. Extension-less storage id: probe known image extensions by stat.
    base = identifier.sub(/\.[A-Za-z0-9]+\z/, '')
    IMAGE_EXTENSIONS.each do |ext|
      candidate = File.join(ORIGINAL_DIR, "#{base}.#{ext}")
      return candidate if File.file?(candidate)
    end

    nil
  end

  # Allow every request by default. When module Access is used, replace this
  # with its authorization logic (see the header note about merging).
  def pre_authorize(options = {})
    true
  end

  def authorize(options = {})
    true
  end

  # Stubs required by the Cantaloupe delegate interface. Return defaults so
  # every IIIF endpoint works without further customization.
  def deserialize_meta_identifier(meta_identifier); {}; end
  def serialize_meta_identifier(components); ''; end
  def extra_iiif2_information_response_keys(options = {}); {}; end
  def extra_iiif3_information_response_keys(options = {}); {}; end
  def azurestoragesource_blob_key(options = {}); nil; end
  def httpsource_resource_info(options = {}); nil; end
  def jdbcsource_database_identifier(options = {}); nil; end
  def jdbcsource_last_modified(options = {}); nil; end
  def jdbcsource_media_type(options = {}); nil; end
  def overlay(options = {}); nil; end
  def redactions(options = {}); []; end
  def metadata(options = {}); nil; end

end
