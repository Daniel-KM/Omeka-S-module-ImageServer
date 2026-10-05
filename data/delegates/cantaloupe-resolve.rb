# Cantaloupe delegate script for Omeka S module Image Server.
#
# Case B: resolve any IIIF identifier to an original file, including a clean or
# "ark" identifier that is NOT derivable from the filename. The identifier
# resolution (CleanUrl, value lookup, storage id…) stays in Omeka/PHP and is
# reused as-is through the endpoint "/iiif-resolve"; this delegate only calls it
# and caches the result, so the file extension and the internal storage name
# stay hidden from the public IIIF url.
#
# Images only: audio and video are served by Iiif Server, not Cantaloupe, so
# they never reach this delegate (and the endpoint resolves images only).
#
# Install:
#   1. Copy to /etc/cantaloupe/delegates.rb (or mount it in the container).
#   2. In cantaloupe.properties:
#        delegate_script.enabled          = true
#        delegate_script.pathname         = /etc/cantaloupe/delegates.rb
#        source.static                    = FilesystemSource
#        FilesystemSource.lookup_strategy = ScriptLookupStrategy
#   3. Set OMEKA_HOST / OMEKA_PORT (Omeka, reachable from the container) and
#      ORIGINAL_DIR (originals directory as mounted in the container).
#   4. Add the Cantaloupe container ip to the Image Server setting
#      "imageserver_external_resolver_ips" so the endpoint answers.
#   5. Restart Cantaloupe.
#
# Coexistence with module Access: Cantaloupe loads a single delegates.rb. When
# the Access authorization delegate is also used, merge its "pre_authorize" /
# "fetch_decision" methods into this class, keeping the resolution methods here.

require 'net/http'
require 'uri'

class CustomDelegate

  attr_accessor :context

  # Omeka, reachable from inside the Cantaloupe container.
  OMEKA_HOST = ENV['OMEKA_HOST'] || 'omeka.example.org'
  OMEKA_PORT = (ENV['OMEKA_PORT'] || 80).to_i

  # Originals directory, as mounted inside the Cantaloupe container.
  ORIGINAL_DIR = ENV['OMEKA_ORIGINAL_DIR'] || '/files/original'

  # Identifier resolver endpoint (restricted to trusted ips on the Omeka side).
  RESOLVE_PATH = '/iiif-resolve'

  # The identifier to file mapping is immutable, so cache aggressively.
  CACHE_TTL = 3600
  CACHE_MAX = 5000

  @@cache = {}
  @@mutex = Mutex.new

  def source(options = {})
    'FilesystemSource'
  end

  def filesystemsource_pathname(options = {})
    identifier = context['identifier'].to_s
    return nil if identifier.empty?

    now = Time.now.to_i
    @@mutex.synchronize do
      hit = @@cache[identifier]
      return hit[:path] if hit && hit[:expires] > now
    end

    filename = fetch_filename(identifier)
    path = filename ? File.join(ORIGINAL_DIR, filename) : nil

    @@mutex.synchronize do
      @@cache[identifier] = { path: path, expires: now + CACHE_TTL }
      if @@cache.size > CACHE_MAX
        @@cache.delete_if { |_, v| v[:expires] < now }
      end
    end

    path
  end

  # Ask Omeka for the internal storage filename of an identifier. Returns nil on
  # any error so Cantaloupe answers 404.
  def fetch_filename(identifier)
    uri = URI::HTTP.build(
      host: OMEKA_HOST,
      port: OMEKA_PORT,
      path: RESOLVE_PATH,
      query: URI.encode_www_form(identifier: identifier)
    )
    res = Net::HTTP.start(uri.host, uri.port, open_timeout: 2, read_timeout: 2) do |http|
      http.get(uri.request_uri)
    end
    return nil unless res.is_a?(Net::HTTPSuccess)

    body = res.body.to_s.strip
    # A storage filename is "<hash>.<ext>": reject empty, traversal and slash
    # (slash-bearing storage ids from module ArchiveRepertory need adaptation).
    return nil if body.empty? || body.include?('/') || body.include?('..')

    body
  rescue StandardError
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

  # Stubs required by the Cantaloupe delegate interface.
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
